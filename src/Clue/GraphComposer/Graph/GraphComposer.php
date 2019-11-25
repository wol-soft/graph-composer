<?php

namespace Clue\GraphComposer\Graph;

use Clue\GraphComposer\Exclusion\Dependency\ChainedDependencyRule;
use Clue\GraphComposer\Exclusion\Dependency\DependencyRule;
use Clue\GraphComposer\Exclusion\Package\ChainedPackageRule;
use Clue\GraphComposer\Exclusion\Package\PackageRule;
use Clue\GraphComposer\Exporter\ExporterFactory;
use Exception;
use Fhaculty\Graph\Attribute\AttributeAware;
use Fhaculty\Graph\Attribute\AttributeBagNamespaced;
use Fhaculty\Graph\Graph;
use Graphp\GraphViz\GraphViz;
use JMS\Composer\DependencyAnalyzer;
use JMS\Composer\Graph\DependencyEdge;
use JMS\Composer\Graph\PackageNode;

class GraphComposer
{
    const ABANDONED_PACKAGE = -1;
    const LATEST            = 0;
    const PATCH_AVAILABLE   = 1;
    const MINOR_AVAILABLE   = 2;
    const MAJOR_AVAILABLE   = 3;

    const VERTEX_COLORS = [
        self::ABANDONED_PACKAGE => '#FF5A52',
        self::MAJOR_AVAILABLE => '#FF7e0d',
        self::MINOR_AVAILABLE => '#FFFA5C',
        self::PATCH_AVAILABLE => '#90DB27',
        self::LATEST => '#3ABA4D',
    ];

    private $layoutVertex = array(
        'style' => 'filled, rounded',
        'shape' => 'box',
        'fontcolor' => '#314B5F',
    );

    private $layoutVertexRoot = array(
        'style' => 'filled, rounded, bold',
    );

    private $layoutEdge = array(
        'fontcolor' => '#767676',
        'fontsize' => 10,
        'color' => '#1A2833',
    );

    private $layoutEdgeDev = array(
        'style' => 'dashed',
        'fontcolor' => '#767676',
        'fontsize' => 10,
        'color' => '#1A2833',
    );

    private $dependencyGraph;

    /** @var GraphViz */
    private $graphviz;

    /** @var string */
    private $dir;

    /** @var array */
    private $versions;

    /**
     * The maximum depth of dependency to display.
     *
     * @var int
     */
    private $maxDepth;

    /**
     * @var bool
     */
    private $colorize;

    /**
     * @var string
     */
    private $exportFile;

    /**
     * @var PackageRule
     */
    private $packageExclusionRule;

    /**
     * @var DependencyRule
     */
    private $dependencyExclusionRule;

    public function __construct(
        $dir,
        GraphViz $graphviz = null,
        PackageRule $packageExclusionRule = null,
        DependencyRule $dependencyExclusionRule = null,
        $maxDepth = PHP_INT_MAX,
        $colorize = false,
        $exportFile = null
    ) {
        if ($graphviz === null) {
            $graphviz = new GraphViz();
            $graphviz->setFormat('svg');
        }


        if ($packageExclusionRule === null) {
            $packageExclusionRule = new ChainedPackageRule();
        }

        if ($dependencyExclusionRule === null) {
            $dependencyExclusionRule = new ChainedDependencyRule();
        }

        $analyzer = new DependencyAnalyzer();
        $this->dependencyGraph = $analyzer->analyze($dir);
        $this->graphviz = $graphviz;
        $this->dir = $dir;
        $this->packageExclusionRule = $packageExclusionRule;
        $this->dependencyExclusionRule = $dependencyExclusionRule;
        $this->maxDepth = $maxDepth;
        $this->colorize = $colorize;
        $this->exportFile = $exportFile;
    }

    /**
     * @return Graph
     * @throws Exception
     */
    public function createGraph()
    {
        $graph = new Graph();

        $drawnPackages = array();
        $rootPackage = $this->dependencyGraph->getRootPackage();
        $this->drawPackageNode($graph, $rootPackage, $drawnPackages, $this->layoutVertexRoot);

        if ($this->exportFile) {
            if (!preg_match('/^.+\.[\w]+$/', $this->exportFile)) {
                throw new Exception("Invalid export file name {$this->exportFile}");
            }

            $fileNameParts = explode('.', $this->exportFile);
            file_put_contents(
                $this->exportFile,
                (new ExporterFactory())->getExporter(end($fileNameParts))->exportGraph(
                    $this->getExportData($drawnPackages)
                )
            );
        }

        return $graph;
    }

    public function displayGraph()
    {
        $graph = $this->createGraph();

        $this->graphviz->display($graph);
    }

    public function getImagePath()
    {
        $graph = $this->createGraph();

        return $this->graphviz->createImageFile($graph);
    }

    private function drawPackageNode(
        Graph $graph,
        PackageNode $packageNode,
        array &$drawnPackages,
        array $layoutVertex = null,
        $depth = 0
    ) {
        // the root package may not excluded
        // beginning with $depth = 1 the packages are filtered using the exclude rule
        if ($depth > 0 && $this->packageExclusionRule->isExcluded($packageNode)) {
            return null;
        }

        $name = $packageNode->getName();
        // ensure that packages are only drawn once
        // if two packages in the tree require a package twice
        // then this dependency does not need to be drawn twice
        // and the vertex is returned directly (so an edge can be added)
        if (isset($drawnPackages[$name])) {
            return $drawnPackages[$name];
        }

        if ($depth > $this->maxDepth) {
            return null;
        }

        if ($layoutVertex === null) {
            $layoutVertex = $this->layoutVertex;
        }

        $vertex = $drawnPackages[$name] = $graph->createVertex($name, true);

        $label = $name;
        if ($packageNode->getVersion()) {
            $label .= ': ' .$packageNode->getVersion();
        }

        $this->setLayout(
            $vertex,
            [
                'fillcolor' => $this->colorize
                    ? self::VERTEX_COLORS[$this->getCurrentPackageVersionStatus($packageNode->getName())]
                    : '#eeeeee',
                'label' => $label
            ] + $this->layoutVertex
        );

        // this foreach will loop over the dependencies of the current package
        foreach ($packageNode->getOutEdges() as $dependency) {
            if ($this->dependencyExclusionRule->isExcluded($dependency)) {
                continue;
            }

            // never show dev dependencies of dependencies:
            // they are not relevant for the current application and are ignored by composer
            if ($depth > 0 && $dependency->isDevDependency()) {
                continue;
            }

            $targetVertex = $this->drawPackageNode($graph, $dependency->getDestPackage(), $drawnPackages, null, $depth + 1);

            // drawPackageNode will return null if the package should not be shown
            // also the dependencies of a package will be only drawn if max depth is not reached
            // this ensures that packages in a deeper level will not have any dependency
            if ($targetVertex && $depth < $this->maxDepth) {
                $label = $dependency->getVersionConstraint();
                $edge = $vertex->createEdgeTo($targetVertex);
                $layoutEdge = $dependency->isDevDependency() ? $this->layoutEdgeDev : $this->layoutEdge;
                $this->setLayout($edge, array('label' => $label) + $layoutEdge);
            }
        }

        return $vertex;
    }

    private function setLayout(AttributeAware $entity, array $layout)
    {
        $bag = new AttributeBagNamespaced($entity->getAttributeBag(), 'graphviz.');
        $bag->setAttributes($layout);

        return $entity;
    }

    public function setFormat($format)
    {
        $this->graphviz->setFormat($format);

        return $this;
    }

    protected function getCurrentPackageVersionStatus($package)
    {
        if (!$this->versions) {
            foreach (explode("\n", shell_exec("cd {$this->dir} && composer outdated  2>&1")) as $package) {
                $parts = preg_split('/\s+/', $package);

                if (strstr($package, 'abandoned')) {
                    $this->versions[$parts[1]] = self::ABANDONED_PACKAGE;
                    continue;
                }

                // filter out lines which don't provide package information
                if (!preg_match('/[^\s]+\s+(v?\d+(.\d+){2}.*?){2}/', $package)) {
                    continue;
                }

                $currentVersion = explode('.', $parts[1]);
                $latestVersion = explode('.', $parts[3]);

                if ($currentVersion[0] !== $latestVersion[0]) {
                    $this->versions[$parts[0]] = self::MAJOR_AVAILABLE;
                } elseif ($currentVersion[1] !== $latestVersion[1]) {
                    $this->versions[$parts[0]] = self::MINOR_AVAILABLE;
                } elseif ($currentVersion[2] !== $latestVersion[2]) {
                    $this->versions[$parts[0]] = self::PATCH_AVAILABLE;
                } else {
                    $this->versions[$parts[0]] = self::LATEST;
                }
            }
        }

        return $this->versions[$package] ?? self::LATEST;
    }

    protected function getExportData(array $drawnPackages)
    {
        // make sure $this->versions is populated
        $this->getCurrentPackageVersionStatus(null);

        foreach (array_diff(array_keys($this->versions), array_keys($drawnPackages)) as $excludedPackage) {
            unset($this->versions[$excludedPackage]);
        }

        $getStatus = function ($status) {
            return count(
                array_filter(
                    $this->versions,
                    function ($packageStatus) use ($status) {
                        return $status === $packageStatus;
                    }
                )
            );
        };

        $directDependencies = count(
            array_filter(
                $this->dependencyGraph->getRootPackage()->getOutEdges(),
                function (DependencyEdge $dependency) use ($drawnPackages) {
                    return in_array($dependency->getDestPackage()->getName(), array_keys($drawnPackages));
                }
            )
        );

        return [
            'dependencies' => [
                'direct' => $directDependencies,
                // minus one --> root package
                'indirect' => count($drawnPackages) - 1 - $directDependencies,
                'total' => count($drawnPackages) - 1,
            ],
            'dependencyStatus' => [
                'latest' => count($drawnPackages) - 1 - count($this->versions),
                'patchAvailable' => $getStatus(self::PATCH_AVAILABLE),
                'minorAvailable' => $getStatus(self::MINOR_AVAILABLE),
                'majorAvailable' => $getStatus(self::MAJOR_AVAILABLE),
                'abandoned' => $getStatus(self::ABANDONED_PACKAGE),
            ]
        ];
    }
}
