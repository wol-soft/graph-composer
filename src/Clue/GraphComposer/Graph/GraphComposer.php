<?php

namespace Clue\GraphComposer\Graph;

use Fhaculty\Graph\Graph;
use Fhaculty\Graph\Attribute\AttributeAware;
use Fhaculty\Graph\Attribute\AttributeBagNamespaced;
use Graphp\GraphViz\GraphViz;

class GraphComposer
{
    const ABANDONED_PACKAGE = -1;
    const LATEST            = 0;
    const PATCH_AVAILABLE   = 1;
    const MINOR_AVAILABLE   = 2;
    const MAJOR_AVAILABLE   = 3;

    const VERTEX_COLORS = [
        self::ABANDONED_PACKAGE => '#A31717',
        self::MAJOR_AVAILABLE => '#FF7e0d',
        self::MINOR_AVAILABLE => '#FFFA5C',
        self::PATCH_AVAILABLE => '#90DB27',
        self::LATEST => '#3ABA4D',
    ];

    private $layoutVertex = array(
        'fillcolor' => '#eeeeee',
        'style' => 'filled, rounded',
        'shape' => 'box',
        'fontcolor' => '#314B5F'
    );

    private $layoutVertexRoot = array(
        'style' => 'filled, rounded, bold'
    );

    private $layoutEdge = array(
        'fontcolor' => '#767676',
        'fontsize' => 10,
        'color' => '#1A2833'
    );

    private $layoutEdgeDev = array(
        'style' => 'dashed'
    );

    private $dependencyGraph;

    /** @var GraphViz */
    private $graphviz;

    /** @var string */
    private $dir;

    /** @var array */
    private $versions;

    /**
     *
     * @param string $dir
     * @param GraphViz|null $graphviz
     */
    public function __construct($dir, GraphViz $graphviz = null)
    {
        if ($graphviz === null) {
            $graphviz = new GraphViz();
            $graphviz->setFormat('svg');
        }

        $analyzer = new \JMS\Composer\DependencyAnalyzer();
        $this->dependencyGraph = $analyzer->analyze($dir);
        $this->graphviz = $graphviz;
        $this->dir = $dir;
    }

    /**
     * @return \Fhaculty\Graph\Graph
     */
    public function createGraph()
    {
        $graph = new Graph();

        foreach ($this->dependencyGraph->getPackages() as $package) {
            $name = $package->getName();
            $start = $graph->createVertex($name, true);

            $label = $name;
            if ($package->getVersion() !== null) {
                $label .= ': ' . $package->getVersion();
            }

            $this->setLayout(
                $start,
                [
                    'color' => self::VERTEX_COLORS[$this->getCurrentPackageVersionStatus($package->getName())],
                    'label' => $label
                ] + $this->layoutVertex
            );

            foreach ($package->getOutEdges() as $requires) {
                $targetName = $requires->getDestPackage()->getName();
                $target = $graph->createVertex($targetName, true);

                $label = $requires->getVersionConstraint();

                $edge = $start->createEdgeTo($target);
                $this->setLayout($edge, array('label' => $label) + $this->layoutEdge);

                if ($requires->isDevDependency()) {
                    $this->setLayout($edge, $this->layoutEdgeDev);
                }
            }
        }

        $root = $graph->getVertex($this->dependencyGraph->getRootPackage()->getName());
        $this->setLayout($root, $this->layoutVertexRoot);

        return $graph;
    }

    private function setLayout(AttributeAware $entity, array $layout)
    {
        $bag = new AttributeBagNamespaced($entity->getAttributeBag(), 'graphviz.');
        $bag->setAttributes($layout);

        return $entity;
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
}
