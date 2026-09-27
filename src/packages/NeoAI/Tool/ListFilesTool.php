<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Tool;

use NeoPHP\Package\NeoAI\Contract\ToolInterface;

class ListFilesTool implements ToolInterface
{
    public const LIMIT = 300;

    public function getName(): string
    {
        return 'list_files';
    }

    public function getUsage(): string
    {
        return 'list_files {"dir": "src", "pattern": "*.php"} : lists the files of a project directory (recursive, excluded paths hidden).';
    }

    public function execute(array $arguments, ToolRunner $runner): string
    {
        $directory = (string) ($arguments['dir'] ?? $arguments['path'] ?? '');
        $pattern = (string) ($arguments['pattern'] ?? '*');
        $files = $runner->getSandbox()->listFiles($directory, $pattern, self::LIMIT + 1);
        $more = count($files) > self::LIMIT;
        $files = array_slice($files, 0, self::LIMIT);

        if ($files === []) {
            return sprintf('No file matching "%s" in "%s".', $pattern, $directory === '' ? '.' : $directory);
        }

        return implode("\n", $files) . ($more ? "\n[... more files, refine dir/pattern]" : '');
    }
}