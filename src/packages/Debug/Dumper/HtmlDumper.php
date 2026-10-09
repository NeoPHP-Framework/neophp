<?php

declare(strict_types=1);

namespace NeoPHP\Package\Debug\Dumper;

class HtmlDumper extends AbstractDumper
{
    public const INDENT = '  ';

    protected bool $assetsDumped = false;

    public function __construct(protected int $expandDepth = 1)
    {
    }

    public static function getStyles(): string
    {
        return '<style>'
            . 'pre.nd{background:#18171b;color:#ff8400;font:12px/1.4 Menlo,Monaco,Consolas,monospace;padding:.6em 1em;margin:.5em 0;border-radius:4px;overflow:auto;white-space:pre;word-wrap:normal;position:relative;z-index:99999;text-align:left}'
            . 'pre.nd .nd-str{color:#56db3a}pre.nd .nd-num,pre.nd .nd-const{color:#1299da;font-weight:bold}'
            . 'pre.nd .nd-note{color:#1299da}pre.nd .nd-class{color:#4ea2f5}pre.nd .nd-key{color:#fff}'
            . 'pre.nd .nd-meta,pre.nd .nd-cut{color:#a0a0a0}pre.nd abbr{text-decoration:none;border:0;cursor:help}'
            . 'pre.nd .nd-label{display:block;color:#a0a0a0;margin-bottom:.3em}'
            . 'pre.nd .nd-toggle{color:#a0a0a0;cursor:pointer;user-select:none;text-decoration:none}'
            . 'pre.nd .nd-toggle::before{content:"\25BC"}pre.nd .nd-toggle.nd-closed::before{content:"\25B6"}'
            . 'pre.nd .nd-kids.nd-closed{display:none}pre.nd .nd-kids.nd-closed+.nd-dots{display:inline}pre.nd .nd-dots{display:none;color:#a0a0a0}'
            . '</style>';
    }

    public function resetAssets(): static
    {
        $this->assetsDumped = false;

        return $this;
    }

    public function dump(array $node, ?string $label = null): string
    {
        $assets = '';

        if (!$this->assetsDumped) {
            $assets = self::getStyles();
            $this->assetsDumped = true;
        }

        $header = $label !== null ? '<span class="nd-label">' . self::e($label) . '</span>' : '';

        return $assets . '<pre class="nd" data-neo-dump>' . $header . $this->render($node, 0) . "</pre>\n";
    }

    protected function render(array $node, int $level): string
    {
        return match ($node['type']) {
            'null' => $this->span('const', 'null'),
            'bool' => $this->span('const', $node['value'] ? 'true' : 'false'),
            'int' => $this->span('num', (string) $node['value']),
            'float' => $this->span('num', self::formatFloat($node['value'])),
            'string' => $this->string($node),
            'array' => $this->collection($node, $level),
            'object' => $this->object($node, $level),
            'enum' => $this->className($node['class']) . '::' . $this->span('const', $node['name']) . ($node['value'] !== null ? ' ' . $this->render($node['value'], $level) : ''),
            'resource' => $this->span('note', 'resource') . ' (' . self::e($node['kind']) . ') ' . $this->span('meta', '#' . $node['id']),
            default => '?',
        };
    }

    protected function string(array $node): string
    {
        $value = ($node['binary'] ? 'b' : '') . '"' . self::escapeString($node['value'], $node['binary']) . ($node['cut'] > 0 ? '…' : '') . '"';
        $title = $node['binary'] ? 'binary string, ' . $node['length'] . ' bytes' : $node['length'] . ' characters';

        return '<span class="nd-str" title="' . self::e($title) . '">' . self::e($value) . '</span>' . ($node['cut'] > 0 ? $this->span('cut', ' +' . $node['cut']) : '');
    }

    protected function collection(array $node, int $level): string
    {
        $head = $this->span('note', 'array:' . $node['count']);

        if ($node['count'] === 0) {
            return $head . ' []';
        }

        if ($node['children'] === null) {
            return $head . ' [' . $this->span('cut', '…') . ']';
        }

        $lines = [];
        $indent = str_repeat(self::INDENT, $level + 1);

        foreach ($node['children'] as $child) {
            $key = is_int($child['key']) ? $this->span('num', (string) $child['key']) : $this->span('str', '"' . self::escapeString((string) $child['key'], false) . '"');
            $lines[] = $indent . $key . ' => ' . $this->render($child['value'], $level + 1);
        }

        if ($node['cut'] > 0) {
            $lines[] = $indent . $this->span('cut', '…' . $node['cut']);
        }

        return $head . ' [' . $this->children($lines, $level) . ']';
    }

    protected function object(array $node, int $level): string
    {
        $head = $this->className($node['class']) . ' ' . $this->span('meta', '{#' . $node['id']);

        if ($node['seen']) {
            return $head . $this->span('meta', '}');
        }

        if ($node['children'] === null) {
            return $head . ' ' . $this->span('cut', '…') . $this->span('meta', '}');
        }

        if ($node['children'] === [] && $node['cut'] === 0) {
            return $head . $this->span('meta', '}');
        }

        $lines = [];
        $indent = str_repeat(self::INDENT, $level + 1);

        foreach ($node['children'] as $child) {
            $prefix = self::VISIBILITY_PREFIXES[$child['visibility']] ?? '';
            $title = $child['visibility'] . ($child['declaring'] !== null ? ' ' . $child['declaring'] : '');
            $lines[] = $indent . '<span class="nd-key" title="' . self::e($title) . '">' . self::e($prefix . $child['key']) . '</span>: ' . $this->render($child['value'], $level + 1);
        }

        if ($node['cut'] > 0) {
            $lines[] = $indent . $this->span('cut', '…' . $node['cut']);
        }

        return $head . ' ' . $this->children($lines, $level) . $this->span('meta', '}');
    }

    protected function children(array $lines, int $level): string
    {
        $closed = $level >= $this->expandDepth ? ' nd-closed' : '';

        return '<a class="nd-toggle' . $closed . '" onclick="this.classList.toggle(\'nd-closed\');this.nextElementSibling.classList.toggle(\'nd-closed\')"></a>'
            . '<samp class="nd-kids' . $closed . '">' . "\n" . implode("\n", $lines) . "\n" . str_repeat(self::INDENT, $level) . '</samp>'
            . '<span class="nd-dots">…</span>';
    }

    protected function className(string $class): string
    {
        return '<abbr class="nd-class" title="' . self::e($class) . '">' . self::e(self::shortClass($class)) . '</abbr>';
    }

    protected function span(string $style, string $value): string
    {
        return '<span class="nd-' . $style . '">' . self::e($value) . '</span>';
    }

    protected static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}