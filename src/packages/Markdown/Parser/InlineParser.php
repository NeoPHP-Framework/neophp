<?php

declare(strict_types=1);

namespace NeoPHP\Package\Markdown\Parser;

class InlineParser
{
    public const ASCII_PUNCTUATION = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    public const HTML_TAG = '/^(?:<[A-Za-z][A-Za-z0-9-]*(?:\s+[A-Za-z_:][A-Za-z0-9_.:-]*(?:\s*=\s*(?:[^"\'=<>`\s]+|\'[^\']*\'|"[^"]*"))?)*\s*\/?>|<\/[A-Za-z][A-Za-z0-9-]*\s*>|<!---->|<!--(?:-?[^>-])(?:-?[^-])*-->|<\?.*?\?>|<![A-Za-z]+[^>]*>|<!\[CDATA\[.*?\]\]>)/s';

    public const SAFE_DATA_URL = '/^data:image\/(?:png|gif|jpe?g|webp|avif|bmp);/';

    protected array $references = [];

    protected array $links = [];

    protected array $images = [];

    protected array $chars = [];

    protected array $offsets = [];

    protected string $text = '';

    protected int $length = 0;

    protected int $pos = 0;

    protected array $nodes = [];

    protected array $brackets = [];

    protected bool $escapeHtml = false;

    public function setEscapeHtml(bool $escapeHtml): static
    {
        $this->escapeHtml = $escapeHtml;

        return $this;
    }

    public function setReferences(array $references): static
    {
        $this->references = $references;

        return $this;
    }

    public function reset(): void
    {
        $this->links = [];
        $this->images = [];
    }

    public function getLinks(): array
    {
        return $this->links;
    }

    public function getImages(): array
    {
        return $this->images;
    }

    public function parse(string $text): string
    {
        $this->text = $text;
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $this->chars = is_array($chars) ? $chars : str_split($text);
        $this->length = count($this->chars);
        $this->offsets = [];
        $offset = 0;

        foreach ($this->chars as $char) {
            $this->offsets[] = $offset;
            $offset += strlen($char);
        }

        $this->offsets[] = $offset;
        $this->pos = 0;
        $this->nodes = [];
        $this->brackets = [];

        while ($this->pos < $this->length) {
            $char = $this->chars[$this->pos];

            match ($char) {
                "\n" => $this->lineBreak(),
                '\\' => $this->backslash(),
                '`' => $this->codeSpan(),
                '&' => $this->entity(),
                '<' => $this->angle(),
                '!' => $this->bang(),
                '[' => $this->openBracket(false, 1),
                ']' => $this->closeBracket(),
                '*', '_', '~' => $this->delimiter($char),
                default => $this->plain($char),
            };
        }

        $this->processEmphasis(-1);

        return $this->renderNodes(0, count($this->nodes));
    }

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function unescape(string $text): string
    {
        $text = (string) preg_replace('/\\\\([!-\/:-@\[-`{-~])/', '$1', $text);

        return (string) preg_replace_callback('/&(?:#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6}|[A-Za-z][A-Za-z0-9]{1,31});/', static fn (array $matches): string => self::decodeEntity($matches[0]), $text);
    }

    public static function normalizeLabel(string $label): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($label)), 'UTF-8');
    }

    public static function decodeEntity(string $entity): string
    {
        if (preg_match('/^&#[xX]?0*;$/', $entity) === 1) {
            return "\u{FFFD}";
        }

        $decoded = html_entity_decode($entity, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $decoded === '' ? "\u{FFFD}" : $decoded;
    }

    public static function url(string $url): string
    {
        $check = strtolower((string) preg_replace('/[\x00-\x20\x7F]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        if (preg_match('/^(?:javascript|vbscript|data):/', $check) === 1 && preg_match(self::SAFE_DATA_URL, $check) !== 1) {
            return '#';
        }

        return (string) preg_replace_callback('/%(?![0-9A-Fa-f]{2})|[^A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]/u', static fn (array $matches): string => rawurlencode($matches[0]), $url);
    }

    protected function rest(): string
    {
        return (string) substr($this->text, $this->offsets[$this->pos]);
    }

    protected function addText(string $text): void
    {
        $last = count($this->nodes) - 1;

        if ($last >= 0 && $this->nodes[$last]['type'] === 'text' && !isset($this->nodes[$last]['bracket'])) {
            $this->nodes[$last]['value'] .= $text;

            return;
        }

        $this->nodes[] = ['type' => 'text', 'value' => $text];
    }

    protected function addHtml(string $html): void
    {
        $this->nodes[] = ['type' => 'html', 'value' => $html];
    }

    protected function plain(string $char): void
    {
        if (($char === 'h' || $char === 'w' || $char === 'H' || $char === 'W') && $this->bareAutolink()) {
            return;
        }

        $start = $this->pos;
        $this->pos++;

        while ($this->pos < $this->length && !str_contains("\n\\`&<![]*_~hwHW", $this->chars[$this->pos])) {
            $this->pos++;
        }

        $this->addText(implode('', array_slice($this->chars, $start, $this->pos - $start)));
    }

    protected function lineBreak(): void
    {
        $last = count($this->nodes) - 1;
        $hard = false;

        if ($last >= 0 && $this->nodes[$last]['type'] === 'text' && !isset($this->nodes[$last]['bracket'])) {
            $value = $this->nodes[$last]['value'];
            $hard = str_ends_with($value, '  ');
            $this->nodes[$last]['value'] = rtrim($value, ' ');
        }

        $hard ? $this->addHtml("<br />\n") : $this->addText("\n");
        $this->pos++;
        $this->skipSpaces();
    }

    protected function skipSpaces(): void
    {
        while ($this->pos < $this->length && $this->chars[$this->pos] === ' ') {
            $this->pos++;
        }
    }

    protected function backslash(): void
    {
        $next = $this->chars[$this->pos + 1] ?? '';

        if ($next === "\n") {
            $this->addHtml("<br />\n");
            $this->pos += 2;
            $this->skipSpaces();

            return;
        }

        if ($next !== '' && str_contains(self::ASCII_PUNCTUATION, $next)) {
            $this->addText($next);
            $this->pos += 2;

            return;
        }

        $this->addText('\\');
        $this->pos++;
    }

    protected function codeSpan(): void
    {
        $start = $this->pos;
        $run = $this->runLength('`', $start);
        $search = $start + $run;

        while ($search < $this->length) {
            if ($this->chars[$search] !== '`') {
                $search++;

                continue;
            }

            $closing = $this->runLength('`', $search);

            if ($closing === $run) {
                $code = str_replace("\n", ' ', implode('', array_slice($this->chars, $start + $run, $search - $start - $run)));

                if (strlen($code) >= 2 && $code[0] === ' ' && $code[strlen($code) - 1] === ' ' && trim($code, ' ') !== '') {
                    $code = substr($code, 1, -1);
                }

                $this->addHtml('<code>' . self::escape($code) . '</code>');
                $this->pos = $search + $closing;

                return;
            }

            $search += $closing;
        }

        $this->addText(str_repeat('`', $run));
        $this->pos += $run;
    }

    protected function entity(): void
    {
        if (preg_match('/^&(?:#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6}|[A-Za-z][A-Za-z0-9]{1,31});/', $this->rest(), $matches) === 1) {
            $decoded = self::decodeEntity($matches[0]);

            if ($decoded !== $matches[0]) {
                $this->addText($decoded);
                $this->pos += strlen($matches[0]);

                return;
            }
        }

        $this->addText('&');
        $this->pos++;
    }

    protected function angle(): void
    {
        $rest = $this->rest();

        if (preg_match('/^<([A-Za-z][A-Za-z0-9.+\-]{1,31}:[^<>\x00-\x20]*)>/', $rest, $matches) === 1) {
            $this->addLink($matches[1], $matches[1], null, self::escape($matches[1]));
            $this->pos += mb_strlen($matches[0], 'UTF-8');

            return;
        }

        if (preg_match('/^<([a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*)>/', $rest, $matches) === 1) {
            $this->addLink($matches[1], 'mailto:' . $matches[1], null, self::escape($matches[1]));
            $this->pos += mb_strlen($matches[0], 'UTF-8');

            return;
        }

        if (preg_match(self::HTML_TAG, $rest, $matches) === 1) {
            $this->escapeHtml ? $this->addText($matches[0]) : $this->addHtml($matches[0]);
            $this->pos += mb_strlen($matches[0], 'UTF-8');

            return;
        }

        $this->addText('<');
        $this->pos++;
    }

    protected function bareAutolink(): bool
    {
        $previous = $this->pos > 0 ? $this->chars[$this->pos - 1] : ' ';

        if (preg_match('/^[\s*_~(]$/u', $previous) !== 1) {
            return false;
        }

        foreach ($this->brackets as $index) {
            if (!$this->nodes[$index]['image'] && $this->nodes[$index]['active']) {
                return false;
            }
        }

        if (preg_match('/^(?:https?:\/\/|www\.)[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*[^\s<]*/i', $this->rest(), $matches) !== 1) {
            return false;
        }

        $url = $matches[0];

        while ($url !== '') {
            $last = $url[strlen($url) - 1];

            if (str_contains('?!.,:*_~\'"', $last)) {
                $url = substr($url, 0, -1);
            } elseif ($last === ')' && substr_count($url, ')') > substr_count($url, '(')) {
                $url = substr($url, 0, -1);
            } elseif ($last === ';' && preg_match('/&[A-Za-z0-9]+;$/', $url, $entity) === 1) {
                $url = substr($url, 0, -strlen($entity[0]));
            } else {
                break;
            }
        }

        if (preg_match('/^(?:https?:\/\/|www\.)[A-Za-z0-9]/i', $url) !== 1) {
            return false;
        }

        $href = stripos($url, 'www.') === 0 ? 'http://' . $url : $url;
        $this->addLink($url, $href, null, self::escape($url));
        $this->pos += mb_strlen($url, 'UTF-8');

        return true;
    }

    protected function addLink(string $text, string $url, ?string $title, string $content): void
    {
        $this->links[] = ['text' => $text, 'url' => $url, 'title' => $title];
        $this->addHtml('<a href="' . self::escape(self::url($url)) . '"' . ($title !== null ? ' title="' . self::escape($title) . '"' : '') . '>' . $content . '</a>');
    }

    protected function bang(): void
    {
        if (($this->chars[$this->pos + 1] ?? '') === '[') {
            $this->openBracket(true, 2);

            return;
        }

        $this->addText('!');
        $this->pos++;
    }

    protected function openBracket(bool $image, int $length): void
    {
        $this->nodes[] = ['type' => 'text', 'value' => $image ? '![' : '[', 'bracket' => true, 'image' => $image, 'active' => true, 'start' => $this->pos + $length];
        $this->brackets[] = count($this->nodes) - 1;
        $this->pos += $length;
    }

    protected function closeBracket(): void
    {
        $close = $this->pos;
        $this->pos++;

        if ($this->brackets === []) {
            $this->addText(']');

            return;
        }

        $index = (int) end($this->brackets);
        $bracket = $this->nodes[$index];

        if (!$bracket['active']) {
            array_pop($this->brackets);
            $this->addText(']');

            return;
        }

        $label = implode('', array_slice($this->chars, $bracket['start'], $close - $bracket['start']));
        $target = $this->inlineTarget() ?? $this->referenceTarget($label);

        if ($target === null) {
            array_pop($this->brackets);
            $this->addText(']');

            return;
        }

        [$url, $title, $position] = $target;
        array_pop($this->brackets);
        $this->processEmphasis($index);
        $content = $this->renderNodes($index + 1, count($this->nodes));
        array_splice($this->nodes, $index);
        $this->pos = $position;
        $plain = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $titleAttribute = $title !== null ? ' title="' . self::escape($title) . '"' : '';

        if ($bracket['image']) {
            $this->images[] = ['alt' => $plain, 'url' => $url, 'title' => $title];
            $this->addHtml('<img src="' . self::escape(self::url($url)) . '" alt="' . self::escape($plain) . '"' . $titleAttribute . ' />');

            return;
        }

        $this->links[] = ['text' => $plain, 'url' => $url, 'title' => $title];
        $this->addHtml('<a href="' . self::escape(self::url($url)) . '"' . $titleAttribute . '>' . $content . '</a>');

        foreach ($this->brackets as $open) {
            if (!$this->nodes[$open]['image']) {
                $this->nodes[$open]['active'] = false;
            }
        }
    }

    protected function inlineTarget(): ?array
    {
        $p = $this->pos;

        if (($this->chars[$p] ?? '') !== '(') {
            return null;
        }

        $p = $this->skipWhitespace($p + 1);
        $destination = '';

        if (($this->chars[$p] ?? '') === '<') {
            $p++;

            while ($p < $this->length && $this->chars[$p] !== '>') {
                if ($this->chars[$p] === "\n" || $this->chars[$p] === '<') {
                    return null;
                }

                if ($this->chars[$p] === '\\' && isset($this->chars[$p + 1])) {
                    $destination .= $this->chars[$p] . $this->chars[$p + 1];
                    $p += 2;

                    continue;
                }

                $destination .= $this->chars[$p];
                $p++;
            }

            if ($p >= $this->length) {
                return null;
            }

            $p++;
        } else {
            $depth = 0;

            while ($p < $this->length) {
                $char = $this->chars[$p];

                if ($char === '\\' && isset($this->chars[$p + 1]) && str_contains(self::ASCII_PUNCTUATION, $this->chars[$p + 1])) {
                    $destination .= $char . $this->chars[$p + 1];
                    $p += 2;

                    continue;
                }

                if (preg_match('/^[\x00-\x20\x7F]$/', $char) === 1) {
                    break;
                }

                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    if ($depth === 0) {
                        break;
                    }

                    $depth--;
                }

                $destination .= $char;
                $p++;
            }

            if ($depth !== 0) {
                return null;
            }
        }

        $before = $p;
        $p = $this->skipWhitespace($p);
        $title = null;
        $opener = $this->chars[$p] ?? '';

        if ($p > $before && ($opener === '"' || $opener === "'" || $opener === '(')) {
            $closer = $opener === '(' ? ')' : $opener;
            $q = $p + 1;
            $value = '';

            while ($q < $this->length && $this->chars[$q] !== $closer) {
                if ($this->chars[$q] === '\\' && isset($this->chars[$q + 1])) {
                    $value .= $this->chars[$q] . $this->chars[$q + 1];
                    $q += 2;

                    continue;
                }

                if ($opener === '(' && $this->chars[$q] === '(') {
                    return null;
                }

                $value .= $this->chars[$q];
                $q++;
            }

            if ($q >= $this->length) {
                return null;
            }

            $title = self::unescape($value);
            $p = $this->skipWhitespace($q + 1);
        }

        if (($this->chars[$p] ?? '') !== ')') {
            return null;
        }

        return [self::unescape($destination), $title, $p + 1];
    }

    protected function referenceTarget(string $text): ?array
    {
        $p = $this->pos;
        $label = $text;

        if (($this->chars[$p] ?? '') === '[') {
            $q = $p + 1;
            $inner = '';

            while ($q < $this->length && $this->chars[$q] !== ']') {
                if ($this->chars[$q] === '[') {
                    return null;
                }

                if ($this->chars[$q] === '\\' && isset($this->chars[$q + 1])) {
                    $inner .= $this->chars[$q] . $this->chars[$q + 1];
                    $q += 2;

                    continue;
                }

                $inner .= $this->chars[$q];
                $q++;
            }

            if ($q >= $this->length) {
                return null;
            }

            $p = $q + 1;

            if (trim($inner) !== '') {
                $label = $inner;
            }
        }

        $key = self::normalizeLabel($label);

        if ($key === '' || !isset($this->references[$key])) {
            return null;
        }

        return [$this->references[$key]['url'], $this->references[$key]['title'], $p];
    }

    protected function skipWhitespace(int $p): int
    {
        while ($p < $this->length && ($this->chars[$p] === ' ' || $this->chars[$p] === "\n" || $this->chars[$p] === "\t")) {
            $p++;
        }

        return $p;
    }

    protected function runLength(string $char, int $start): int
    {
        $length = 0;

        while ($start + $length < $this->length && $this->chars[$start + $length] === $char) {
            $length++;
        }

        return $length;
    }

    protected function delimiter(string $char): void
    {
        $count = $this->runLength($char, $this->pos);
        $before = $this->pos > 0 ? $this->chars[$this->pos - 1] : "\n";
        $after = $this->chars[$this->pos + $count] ?? "\n";
        $this->pos += $count;

        if ($char === '~' && $count > 2) {
            $this->addText(str_repeat('~', $count));

            return;
        }

        $beforeSpace = self::isWhitespace($before);
        $afterSpace = self::isWhitespace($after);
        $beforePunct = self::isPunctuation($before);
        $afterPunct = self::isPunctuation($after);
        $left = !$afterSpace && (!$afterPunct || $beforeSpace || $beforePunct);
        $right = !$beforeSpace && (!$beforePunct || $afterSpace || $afterPunct);

        if ($char === '_') {
            $open = $left && (!$right || $beforePunct);
            $close = $right && (!$left || $afterPunct);
        } else {
            $open = $left;
            $close = $right;
        }

        $this->nodes[] = ['type' => 'delim', 'char' => $char, 'count' => $count, 'original' => $count, 'open' => $open, 'close' => $close, 'stack' => true, 'openTags' => '', 'closeTags' => ''];
    }

    protected function processEmphasis(int $bottom): void
    {
        $openersBottom = [];
        $total = count($this->nodes);
        $closer = $bottom + 1;

        while ($closer < $total) {
            $node = $this->nodes[$closer];

            if ($node['type'] !== 'delim' || !$node['stack'] || !$node['close'] || $node['count'] === 0) {
                $closer++;

                continue;
            }

            $key = $node['char'] . ($node['open'] ? '1' : '0') . ($node['original'] % 3);
            $limit = max($bottom, $openersBottom[$key] ?? $bottom);
            $opener = null;

            for ($o = $closer - 1; $o > $limit; $o--) {
                $candidate = $this->nodes[$o];

                if ($candidate['type'] !== 'delim' || !$candidate['stack'] || !$candidate['open'] || $candidate['char'] !== $node['char'] || $candidate['count'] === 0) {
                    continue;
                }

                if ($node['char'] === '~') {
                    if ($candidate['count'] !== $node['count']) {
                        continue;
                    }
                } elseif (($candidate['close'] || $node['open']) && ($candidate['original'] + $node['original']) % 3 === 0 && !($candidate['original'] % 3 === 0 && $node['original'] % 3 === 0)) {
                    continue;
                }

                $opener = $o;

                break;
            }

            if ($opener === null) {
                $openersBottom[$key] = $closer - 1;

                if (!$node['open']) {
                    $this->nodes[$closer]['stack'] = false;
                }

                $closer++;

                continue;
            }

            if ($node['char'] === '~') {
                $use = $node['count'];
                $tag = 'del';
            } else {
                $use = $this->nodes[$opener]['count'] >= 2 && $node['count'] >= 2 ? 2 : 1;
                $tag = $use === 2 ? 'strong' : 'em';
            }

            $this->nodes[$opener]['count'] -= $use;
            $this->nodes[$closer]['count'] -= $use;
            $this->nodes[$opener]['openTags'] = '<' . $tag . '>' . $this->nodes[$opener]['openTags'];
            $this->nodes[$closer]['closeTags'] .= '</' . $tag . '>';

            for ($between = $opener + 1; $between < $closer; $between++) {
                if ($this->nodes[$between]['type'] === 'delim') {
                    $this->nodes[$between]['stack'] = false;
                }
            }

            if ($this->nodes[$opener]['count'] === 0) {
                $this->nodes[$opener]['stack'] = false;
            }

            if ($this->nodes[$closer]['count'] === 0) {
                $this->nodes[$closer]['stack'] = false;
                $closer++;
            }
        }

        for ($index = $bottom + 1; $index < $total; $index++) {
            if ($this->nodes[$index]['type'] === 'delim') {
                $this->nodes[$index]['stack'] = false;
            }
        }
    }

    protected function renderNodes(int $from, int $to): string
    {
        $html = '';

        for ($index = $from; $index < $to; $index++) {
            $node = $this->nodes[$index];

            $html .= match ($node['type']) {
                'text' => self::escape($node['value']),
                'html' => $node['value'],
                default => $node['closeTags'] . self::escape(str_repeat($node['char'], $node['count'])) . $node['openTags'],
            };
        }

        return $html;
    }

    protected static function isWhitespace(string $char): bool
    {
        return preg_match('/^[\s\p{Zs}]$/u', $char) === 1;
    }

    protected static function isPunctuation(string $char): bool
    {
        return preg_match('/^[\p{P}\p{S}]$/u', $char) === 1;
    }
}