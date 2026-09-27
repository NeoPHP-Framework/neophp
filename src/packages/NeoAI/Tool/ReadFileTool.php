<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Tool;

use NeoPHP\Package\NeoAI\Contract\ToolInterface;

class ReadFileTool implements ToolInterface
{
    public function getName(): string
    {
        return 'read_file';
    }

    public function getUsage(): string
    {
        return 'read_file {"path": "src/Controller/HomeController.php", "from": 1, "to": 120} : reads a project file with line numbers (from/to optional).';
    }

    public function execute(array $arguments, ToolRunner $runner): string
    {
        $from = isset($arguments['from']) ? (int) $arguments['from'] : 1;
        $to = isset($arguments['to']) ? (int) $arguments['to'] : null;
        $file = $runner->getSandbox()->read((string) ($arguments['path'] ?? ''), $from, $to);
        $runner->remember($file['path'], $file['hash']);

        return sprintf("File %s (lines %d-%d of %d%s):\n%s", $file['path'], $file['from'], $file['to'], $file['total'], $file['truncated'] ? ', truncated' : '', $file['content']);
    }
}