<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Tool;

use NeoPHP\Package\NeoAI\Contract\ToolInterface;

class SearchTool implements ToolInterface
{
    public const LIMIT = 80;

    public function getName(): string
    {
        return 'search';
    }

    public function getUsage(): string
    {
        return 'search {"regex": "function\\\\s+index", "glob": "*.php", "dir": "src"} : searches a regular expression in the project files (glob and dir optional).';
    }

    public function execute(array $arguments, ToolRunner $runner): string
    {
        $regex = (string) ($arguments['regex'] ?? $arguments['query'] ?? '');

        if ($regex === '') {
            return 'ERROR: the "regex" argument is required.';
        }

        $matches = $runner->getSandbox()->search($regex, (string) ($arguments['glob'] ?? '*'), self::LIMIT, (string) ($arguments['dir'] ?? ''));

        if ($matches === []) {
            return sprintf('No match for /%s/.', $regex);
        }

        $lines = array_map(static fn (array $match): string => sprintf('%s:%d: %s', $match['file'], $match['line'], $match['text']), $matches);

        return implode("\n", $lines) . (count($matches) >= self::LIMIT ? "\n[... limit reached, refine the search]" : '');
    }
}