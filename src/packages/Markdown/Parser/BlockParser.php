<?php

declare(strict_types=1);

namespace NeoPHP\Package\Markdown\Parser;

class BlockParser
{
    public const HTML_BLOCK_TAGS = 'address|article|aside|base|basefont|blockquote|body|caption|center|col|colgroup|dd|details|dialog|dir|div|dl|dt|fieldset|figcaption|figure|footer|form|frame|frameset|h[1-6]|head|header|hr|html|iframe|legend|li|link|main|menu|menuitem|nav|noframes|ol|optgroup|option|p|param|search|section|summary|table|tbody|td|tfoot|th|thead|title|tr|track|ul';

    public const DELIMITER_ROW = '/^ {0,3}\|?[ \t]*:?-+:?[ \t]*(?:\|[ \t]*:?-+:?[ \t]*)*\|?[ \t]*$/';

    protected array $references = [];

    public function parse(string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", "\u{FFFD}"], $markdown);
        $lines = array_map(fn (string $line): string => $this->expandTabs($line), explode("\n", $markdown));

        if (end($lines) === '') {
            array_pop($lines);
        }

        $this->references = [];
        $blocks = $this->parseLines($lines);

        return [$blocks, $this->references];
    }

    public static function indent(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }

    public static function isThematicBreak(string $line): bool
    {
        return preg_match('/^ {0,3}(?:(?:\*[ \t]*){3,}|(?:-[ \t]*){3,}|(?:_[ \t]*){3,})$/', $line) === 1;
    }

    public static function listMarker(string $line): ?array
    {
        if (preg_match('/^( {0,3})([-+*]|(\d{1,9})([.)]))( +|$)(.*)$/', $line, $matches) !== 1) {
            return null;
        }

        $ordered = $matches[3] !== '';
        $width = strlen($matches[1]) + strlen($matches[2]);
        $spaces = strlen($matches[5]);
        $content = $matches[6];

        if ($content === '' || $spaces > 4) {
            $content = $spaces > 4 ? str_repeat(' ', $spaces - 1) . $content : '';
            $spaces = 1;
        }

        return [
            'key' => $ordered ? 'o' . $matches[4] : 'b' . $matches[2],
            'ordered' => $ordered,
            'start' => $ordered ? (int) $matches[3] : 1,
            'indent' => $width + $spaces,
            'content' => $content,
        ];
    }

    public static function htmlBlockType(string $line, bool $interrupt): int
    {
        if (preg_match('/^ {0,3}</', $line) !== 1) {
            return 0;
        }

        $patterns = [
            1 => '/^ {0,3}<(?:script|pre|style|textarea)(?:\s|>|$)/i',
            2 => '/^ {0,3}<!--/',
            3 => '/^ {0,3}<\?/',
            4 => '/^ {0,3}<![A-Za-z]/',
            5 => '/^ {0,3}<!\[CDATA\[/',
            6 => '/^ {0,3}<\/?(?:' . self::HTML_BLOCK_TAGS . ')(?:\s|\/?>|$)/i',
        ];

        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return $type;
            }
        }

        $tag = '/^ {0,3}(?:<[A-Za-z][A-Za-z0-9-]*(?:\s+[A-Za-z_:][A-Za-z0-9_.:-]*(?:\s*=\s*(?:[^"\'=<>`\s]+|\'[^\']*\'|"[^"]*"))?)*\s*\/?>|<\/[A-Za-z][A-Za-z0-9-]*\s*>)[ \t]*$/';

        return !$interrupt && preg_match($tag, $line) === 1 ? 7 : 0;
    }

    public static function splitCells(string $line): array
    {
        $line = trim($line);
        $line = str_starts_with($line, '|') ? substr($line, 1) : $line;
        $line = preg_match('/(?<!\\\\)\|$/', $line) === 1 ? substr($line, 0, -1) : $line;
        $cells = preg_split('/(?<!\\\\)\|/', $line) ?: [];

        return array_map(static fn (string $cell): string => str_replace('\\|', '|', trim($cell)), $cells);
    }

    protected function parseLines(array $lines): array
    {
        $blocks = [];
        $count = count($lines);
        $i = 0;

        while ($i < $count) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;

                continue;
            }

            $start = $i;
            $block = $this->indentedCode($lines, $i)
                ?? $this->fencedCode($lines, $i)
                ?? $this->atxHeading($lines, $i)
                ?? $this->thematicBreak($lines, $i)
                ?? $this->blockquote($lines, $i)
                ?? $this->htmlBlock($lines, $i, false)
                ?? $this->table($lines, $i)
                ?? $this->listBlock($lines, $i)
                ?? $this->paragraph($lines, $i);

            if ($i <= $start) {
                $i = $start + 1;
            }

            if ($block === []) {
                continue;
            }

            $block['start'] = $start;
            $block['end'] = $block['end'] ?? $i - 1;
            $blocks[] = $block;
        }

        return $blocks;
    }

    protected function indentedCode(array $lines, int &$i): ?array
    {
        if (self::indent($lines[$i]) < 4) {
            return null;
        }

        $code = [];
        $count = count($lines);

        while ($i < $count && (trim($lines[$i]) === '' || self::indent($lines[$i]) >= 4)) {
            $code[] = (string) substr($lines[$i], min(4, self::indent($lines[$i])));
            $i++;
        }

        while ($code !== [] && trim((string) end($code)) === '') {
            array_pop($code);
            $i--;
        }

        return ['type' => 'code', 'language' => null, 'code' => implode("\n", $code) . "\n"];
    }

    protected function fencedCode(array $lines, int &$i): ?array
    {
        if (preg_match('/^( {0,3})(`{3,}|~{3,})(.*)$/', $lines[$i], $matches) !== 1) {
            return null;
        }

        $fence = $matches[2];
        $info = trim($matches[3]);

        if ($fence[0] === '`' && str_contains($info, '`')) {
            return null;
        }

        $indent = strlen($matches[1]);
        $language = $info === '' ? null : InlineParser::unescape((string) preg_split('/\s+/', $info)[0]);
        $code = [];
        $count = count($lines);
        $i++;

        while ($i < $count) {
            if (preg_match('/^ {0,3}(' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',})[ \t]*$/', $lines[$i]) === 1) {
                $i++;

                break;
            }

            $code[] = (string) substr($lines[$i], min($indent, self::indent($lines[$i])));
            $i++;
        }

        return ['type' => 'code', 'language' => $language, 'code' => $code === [] ? '' : implode("\n", $code) . "\n"];
    }

    protected function atxHeading(array $lines, int &$i): ?array
    {
        if (preg_match('/^ {0,3}(#{1,6})(?:[ \t]+(.*))?$/', $lines[$i], $matches) !== 1) {
            return null;
        }

        $text = (string) preg_replace('/(?:^|[ \t]+)#+[ \t]*$/', '', trim($matches[2] ?? ''));
        $i++;

        return ['type' => 'heading', 'level' => strlen($matches[1]), 'text' => trim($text)];
    }

    protected function thematicBreak(array $lines, int &$i): ?array
    {
        if (!self::isThematicBreak($lines[$i])) {
            return null;
        }

        $i++;

        return ['type' => 'hr'];
    }

    protected function blockquote(array $lines, int &$i): ?array
    {
        if (preg_match('/^ {0,3}>/', $lines[$i]) !== 1) {
            return null;
        }

        $inner = [];
        $count = count($lines);

        while ($i < $count) {
            if (preg_match('/^ {0,3}> ?(.*)$/', $lines[$i], $matches) === 1) {
                $inner[] = $matches[1];
            } elseif (trim($lines[$i]) !== '' && $inner !== [] && trim((string) end($inner)) !== '' && !$this->isBlockStart($lines[$i]) && !$this->isFencedInside($inner)) {
                $inner[] = $lines[$i];
            } else {
                break;
            }

            $i++;
        }

        return ['type' => 'blockquote', 'children' => $this->parseLines($inner)];
    }

    protected function htmlBlock(array $lines, int &$i, bool $interrupt): ?array
    {
        $type = self::htmlBlockType($lines[$i], $interrupt);

        if ($type === 0) {
            return null;
        }

        $html = [];
        $count = count($lines);
        $ends = [1 => '/<\/(?:script|pre|style|textarea)>/i', 2 => '/-->/', 3 => '/\?>/', 4 => '/>/', 5 => '/\]\]>/'];

        while ($i < $count) {
            if ($type >= 6 && trim($lines[$i]) === '') {
                break;
            }

            $html[] = $lines[$i];
            $i++;

            if ($type <= 5 && preg_match($ends[$type], (string) end($html)) === 1) {
                break;
            }
        }

        return ['type' => 'html', 'html' => implode("\n", $html) . "\n"];
    }

    protected function table(array $lines, int &$i): ?array
    {
        if (!$this->isTableStart($lines, $i)) {
            return null;
        }

        $head = self::splitCells($lines[$i]);
        $aligns = [];

        foreach (self::splitCells($lines[$i + 1]) as $cell) {
            $left = str_starts_with($cell, ':');
            $right = str_ends_with($cell, ':');
            $aligns[] = $left && $right ? 'center' : ($right ? 'right' : ($left ? 'left' : null));
        }

        $rows = [];
        $count = count($lines);
        $i += 2;

        while ($i < $count && trim($lines[$i]) !== '' && !$this->isBlockStart($lines[$i])) {
            $cells = array_slice(self::splitCells($lines[$i]), 0, count($head));
            $rows[] = array_pad($cells, count($head), '');
            $i++;
        }

        return ['type' => 'table', 'aligns' => $aligns, 'head' => $head, 'rows' => $rows];
    }

    protected function listBlock(array $lines, int &$i): ?array
    {
        $first = self::listMarker($lines[$i]);

        if ($first === null || self::isThematicBreak($lines[$i])) {
            return null;
        }

        $items = [];
        $loose = false;
        $count = count($lines);

        while ($i < $count) {
            $marker = self::listMarker($lines[$i]);

            if ($marker === null || $marker['key'] !== $first['key'] || self::isThematicBreak($lines[$i])) {
                break;
            }

            $itemLines = [$marker['content']];
            $contentIndent = $marker['indent'];
            $i++;

            while ($i < $count) {
                $line = $lines[$i];

                if (trim($line) === '') {
                    if (count($itemLines) === 1 && $itemLines[0] === '') {
                        break;
                    }

                    $itemLines[] = '';
                    $i++;

                    continue;
                }

                if (self::indent($line) >= $contentIndent) {
                    $itemLines[] = (string) substr($line, $contentIndent);
                    $i++;

                    continue;
                }

                if (trim((string) end($itemLines)) !== '' && !$this->isBlockStart($line) && !$this->isFencedInside($itemLines) && !$this->endsWithBlock($itemLines)) {
                    $itemLines[] = ltrim($line);
                    $i++;

                    continue;
                }

                break;
            }

            $trailing = 0;

            while (count($itemLines) > 1 && trim((string) end($itemLines)) === '') {
                array_pop($itemLines);
                $trailing++;
            }

            $children = $this->parseLines($itemLines);

            for ($k = 1; $k < count($children); $k++) {
                if ($children[$k]['start'] > $children[$k - 1]['end'] + 1) {
                    $loose = true;
                }
            }

            $task = null;

            if (isset($children[0]) && $children[0]['type'] === 'paragraph' && preg_match('/^\[([ xX])\](?:[ \t]+|$)/', $children[0]['text'], $matches) === 1) {
                $task = strtolower($matches[1]) === 'x';
                $children[0]['text'] = (string) substr($children[0]['text'], strlen($matches[0]));
            }

            $items[] = ['children' => $children, 'task' => $task];

            if ($trailing > 0) {
                $next = $i < $count ? self::listMarker($lines[$i]) : null;

                if ($next !== null && $next['key'] === $first['key'] && !self::isThematicBreak($lines[$i])) {
                    $loose = true;
                } else {
                    $i -= $trailing;

                    break;
                }
            }
        }

        return ['type' => 'list', 'ordered' => $first['ordered'], 'number' => $first['start'], 'loose' => $loose, 'items' => $items];
    }

    protected function paragraph(array $lines, int &$i): array
    {
        $text = [];
        $count = count($lines);

        while ($i < $count && trim($lines[$i]) !== '') {
            if ($text !== [] && preg_match('/^ {0,3}(=+|-+)[ \t]*$/', $lines[$i], $matches) === 1) {
                $content = $this->extractReferences(implode("\n", $text));
                $i++;

                if ($content === '') {
                    return ['type' => 'paragraph', 'text' => trim($matches[0])];
                }

                return ['type' => 'heading', 'level' => $matches[1][0] === '=' ? 1 : 2, 'text' => $content];
            }

            if ($text !== [] && ($this->isBlockStart($lines[$i], true) || $this->isTableStart($lines, $i))) {
                break;
            }

            $text[] = ltrim($lines[$i]);
            $i++;
        }

        $content = $this->extractReferences(implode("\n", $text));

        return $content === '' ? [] : ['type' => 'paragraph', 'text' => $content];
    }

    protected function extractReferences(string $text): string
    {
        $pattern = '/^ {0,3}\[((?:[^\\\\\[\]]|\\\\.){1,999})\]:[ \t]*\n?[ \t]*(<[^<>\n]*>|\S+)(?:(?:[ \t]*\n[ \t]*|[ \t]+)("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\((?:[^()\\\\]|\\\\.)*\)))?[ \t]*(?:\n|$)/s';

        while (preg_match($pattern, $text, $matches) === 1) {
            $label = InlineParser::normalizeLabel($matches[1]);

            if ($label === '') {
                break;
            }

            $url = $matches[2];
            $url = str_starts_with($url, '<') ? substr($url, 1, -1) : $url;

            if (!isset($this->references[$label])) {
                $this->references[$label] = [
                    'url' => InlineParser::unescape($url),
                    'title' => isset($matches[3]) ? InlineParser::unescape(substr($matches[3], 1, -1)) : null,
                ];
            }

            $text = (string) substr($text, strlen($matches[0]));
        }

        return rtrim($text);
    }

    protected function isBlockStart(string $line, bool $interrupt = false): bool
    {
        if (preg_match('/^ {0,3}(?:`{3,}(?!.*`)|~{3,}|#{1,6}(?:[ \t]|$)|>)/', $line) === 1 || self::isThematicBreak($line)) {
            return true;
        }

        if (self::htmlBlockType($line, true) !== 0) {
            return true;
        }

        $marker = self::listMarker($line);

        if ($marker === null) {
            return false;
        }

        return !$interrupt || (trim($marker['content']) !== '' && (!$marker['ordered'] || $marker['start'] === 1));
    }

    protected function isTableStart(array $lines, int $i): bool
    {
        if (!isset($lines[$i + 1]) || !str_contains($lines[$i], '|') || !str_contains($lines[$i + 1], '|') || self::indent($lines[$i]) >= 4) {
            return false;
        }

        if (preg_match(self::DELIMITER_ROW, $lines[$i + 1]) !== 1) {
            return false;
        }

        return count(self::splitCells($lines[$i])) === count(self::splitCells($lines[$i + 1]));
    }

    protected function isFencedInside(array $lines): bool
    {
        $fence = null;

        foreach ($lines as $line) {
            if ($fence === null && preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $matches) === 1) {
                $fence = $matches[1];
            } elseif ($fence !== null && preg_match('/^ {0,3}' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}[ \t]*$/', $line) === 1) {
                $fence = null;
            }
        }

        return $fence !== null;
    }

    protected function endsWithBlock(array $lines): bool
    {
        $last = (string) end($lines);

        return self::indent($last) >= 4 || preg_match('/^ {0,3}(?:#{1,6}(?:[ \t]|$)|`{3,}|~{3,})/', $last) === 1 || self::isThematicBreak($last);
    }

    protected function expandTabs(string $line): string
    {
        if (!str_contains($line, "\t")) {
            return $line;
        }

        $result = '';
        $length = strlen($line);

        for ($k = 0; $k < $length; $k++) {
            $char = $line[$k];

            if ($char === "\t") {
                $result .= str_repeat(' ', 4 - (strlen($result) % 4));
            } elseif ($char === ' ') {
                $result .= ' ';
            } else {
                return $result . substr($line, $k);
            }
        }

        return $result;
    }
}