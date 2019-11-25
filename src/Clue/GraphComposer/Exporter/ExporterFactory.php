<?php

namespace Clue\GraphComposer\Exporter;

use Exception;

class ExporterFactory
{
    /**
     * @param string $exportFormat
     *
     * @return ExporterInterface
     *
     * @throws Exception
     */
    public function getExporter($exportFormat)
    {
        $exportFormat = strtoupper($exportFormat);
        $class = "Clue\GraphComposer\Exporter\{$exportFormat}Exporter";

        if (!class_exists($class)) {
            throw new Exception("Not supported export format $exportFormat");
        }

        return new $class();
    }
}
