<?php

declare(strict_types=1);

namespace NeoPHP\Package\Debug\Dumper;

class CliDumper extends AbstractDumper
{
    public const STYLES = [
        'const' => '1;35',
        'num' => '1;34',
        'str' => '32',
        'note' => '33',
        'class' => '36',
        'key' => '0',
        'meta' => '90',
        'cut' => '2',
    ];

    public const INDENT = '  ';

    public function __construct(protected bool $colors = false)
    {
    }

    public function hasColors(): bool
    {
        return $this->colors;
    }

    public function dump(array $node, ?string $label = null): string
    {
        $header = $label !== null ? $this->style('meta', '// ' . $label) . "\n" : '';

        return $header . $this->render($node, 0) . "\n";
    }

    protected function render(array $node, int $level): string
    {
        return match ($node['type']) {
            'null' => $this->style('const', 'null'),
            'bool' => $this->style('const', $node['value'] ? 'true' : 'false'),
            'int' => $this->style('num', (string) $node['value']),
            'float' => $this->style('num', self::formatFloat($node['value'])),
            'string' => $this->string($node),
            'array' => $this->collection($node, $level),
            'object' => $this->object($node, $level),
            'enum' => $this->style('class', $node['class']) . '::' . $this->style('const', $node['name']) . ($node['value'] !== null ? ' ' . $this->render($node['value'], $level) : ''),
            'resource' => $this->style('note', 'resource') . ' (' . $node['kind'] . ') ' . $this->style('meta', '#' . $node['id']),
            default => '?',
        };
    }

    protected function string(array $node): string
    {
        $value = ($node['binary'] ? 'b' : '') . '"' . self::escapeString($node['value'], $node['binary']) . ($node['cut'] > 0 ? '…' : '') . '"';

        return $this->style('str', $value) . ($node['cut'] > 0 ? $this->style('cut', ' +' . $node['cut']) : '');
    }

    protected function collection(array $node, int $level): string
    {
        $head = $this->style('note', 'array:' . $node['count']);

        if ($node['count'] === 0) {
            return $head . ' []';
        }

        if ($node['children'] === null) {
            return $head . ' [' . $this->style('cut', '…') . ']';
        }

        $lines = [];
        $indent = str_repeat(self::INDENT, $level + 1);

        foreach ($node['children'] as $child) {
            $key = is_int($child['key']) ? $this->style('num', (string) $child['key']) : $this->style('str', '"' . self::escapeString((string) $child['key'], false) . '"');
            $lines[] = $indent . $key . ' => ' . $this->render($child['value'], $level + 1);
        }

        if ($node['cut'] > 0) {
            $lines[] = $indent . $this->style('cut', '…' . $node['cut']);
        }

        return $head . " [\n" . implode("\n", $lines) . "\n" . str_repeat(self::INDENT, $level) . ']';
    }

    protected function object(array $node, int $level): string
    {
        $head = $this->style('class', $node['class']) . ' ' . $this->style('meta', '{#' . $node['id']);

        if ($node['seen']) {
            return $head . $this->style('meta', '}');
        }

        if ($node['children'] === null) {
            return $head . ' ' . $this->style('cut', '…') . $this->style('meta', '}');
        }

        if ($node['children'] === [] && $node['cut'] === 0) {
            return $head . $this->style('meta', '}');
        }

        $lines = [];
        $indent = str_repeat(self::INDENT, $level + 1);

        foreach ($node['children'] as $child) {
            $name = (self::VISIBILITY_PREFIXES[$child['visibility']] ?? '') . $child['key'];

            if ($child['declaring'] !== null) {
                $name .= $this->style('meta', ' (' . self::shortClass($child['declaring']) . ')');
            }

            $lines[] = $indent . $this->style('key', $name) . ': ' . $this->render($child['value'], $level + 1);
        }

        if ($node['cut'] > 0) {
            $lines[] = $indent . $this->style('cut', '…' . $node['cut']);
        }

        return $head . "\n" . implode("\n", $lines) . "\n" . str_repeat(self::INDENT, $level) . $this->style('meta', '}');
    }

    protected function style(string $style, string $value): string
    {
        if (!$this->colors || $value === '') {
            return $value;
        }

        return "\033[" . (self::STYLES[$style] ?? '0') . 'm' . $value . "\033[0m";
    }
}