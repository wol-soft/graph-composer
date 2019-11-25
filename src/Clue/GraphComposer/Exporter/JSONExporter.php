<?php

namespace Clue\GraphComposer\Exporter;

class JSONExporter implements ExporterInterface
{
    public function exportGraph(array $data)
    {
        return json_encode($data, JSON_PRETTY_PRINT);
    }
}
