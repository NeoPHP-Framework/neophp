<?php

declare(strict_types=1);

namespace NeoPHP\Package\Markdown\Converter;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

class HtmlConverter
{
    public const BREAK = "\u{E000}";

    public const IGNORED = ['script', 'style', 'head', 'noscript', 'template', 'iframe', 'object', 'embed', 'svg', 'canvas', 'meta', 'link', 'title', 'button', 'select', 'textarea'];

    public const BLOCKS = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'pre', 'blockquote', 'hr', 'table', 'div', 'section', 'article', 'main', 'header', 'footer', 'nav', 'aside', 'figure', 'figcaption', 'address', 'details', 'summary', 'form', 'fieldset', 'dl', 'dt', 'dd', 'body', 'html', 'li'];

    protected array $codeBlocks = [];

    public function convert(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $this->codeBlocks = [];
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"?><!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $this->body($html) . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $document->getElementsByTagName('body')->item(0);
        $markdown = $body instanceof DOMNode ? $this->blocks($body) : '';
        $markdown = (string) preg_replace('/[ \t]+$/m', '', $markdown);
        $markdown = (string) preg_replace('/\n{3,}/', "\n\n", $markdown);
        $markdown = str_replace(self::BREAK, '  ', $markdown);
        $markdown = (string) preg_replace_callback('/\x{E001}(\d+)\x{E001}/u', fn (array $matches): string => $this->codeBlocks[(int) $matches[1]] ?? '', $markdown);
        $markdown = trim($markdown, "\n");

        return $markdown === '' ? '' : $markdown . "\n";
    }

    protected function body(string $html): string
    {
        $html = str_replace(["\r\n", "\r"], "\n", $html);

        if (preg_match('/<body\b[^>]*>(.*)<\/body>/is', $html, $matches) === 1) {
            return $matches[1];
        }

        $html = (string) preg_replace('/<head\b[^>]*>.*?<\/head>/is', '', $html);

        return (string) preg_replace('/<\/?(?:html|body|!doctype)\b[^>]*>/i', '', $html);
    }

    /** @phpstan-impure */
    protected function blocks(DOMNode $parent): string
    {
        $blocks = [];
        $inline = '';

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->nodeName), self::BLOCKS, true)) {
                $blocks[] = $this->paragraph($inline);
                $inline = '';
                $blocks[] = $this->block($child);

                continue;
            }

            $inline .= $this->inline($child);
        }

        $blocks[] = $this->paragraph($inline);

        return implode("\n\n", array_filter($blocks, static fn (string $block): bool => trim($block) !== ''));
    }

    protected function block(DOMElement $element): string
    {
        $name = strtolower($element->nodeName);

        return match ($name) {
            'p', 'dt', 'summary', 'figcaption' => $this->paragraph($this->children($element)),
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => $this->heading($element, (int) $name[1]),
            'ul', 'ol' => $this->listBlock($element),
            'pre' => $this->pre($element),
            'blockquote' => $this->quote($this->blocks($element)),
            'hr' => '---',
            'table' => $this->table($element),
            'dd' => $this->indentLines($this->blocks($element), '    ', '    '),
            default => $this->blocks($element),
        };
    }

    protected function heading(DOMElement $element, int $level): string
    {
        $text = trim(str_replace(self::BREAK . "\n", ' ', $this->collapse($this->children($element))));

        return $text === '' ? '' : str_repeat('#', $level) . ' ' . $text;
    }

    protected function paragraph(string $inline): string
    {
        $text = $this->collapse($inline);
        $lines = explode("\n", $text);

        foreach ($lines as $index => $line) {
            $lines[$index] = $this->escapeLineStart(trim($line, ' '));
        }

        $text = trim(implode("\n", $lines));

        while (str_ends_with($text, self::BREAK)) {
            $text = rtrim(substr($text, 0, -strlen(self::BREAK)));
        }

        return $text;
    }

    protected function collapse(string $text): string
    {
        $text = (string) preg_replace('/[ \t\n\f]+/', ' ', $text);
        $text = str_replace(self::BREAK . ' ', self::BREAK, $text);
        $text = str_replace(' ' . self::BREAK, self::BREAK, $text);

        return str_replace(self::BREAK, self::BREAK . "\n", trim($text, ' '));
    }

    protected function escapeLineStart(string $line): string
    {
        if (preg_match('/^(#{1,6}(?:\s|$)|>|[-+*](?:\s|$)|=+\s*$)/', $line) === 1) {
            return '\\' . $line;
        }

        if (preg_match('/^(\d+)([.)])(\s|$)/', $line, $matches) === 1) {
            return $matches[1] . '\\' . substr($line, strlen($matches[1]));
        }

        return $line;
    }

    protected function children(DOMNode $node): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            $text .= $this->inline($child);
        }

        return $text;
    }

    protected function inline(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return $this->escape($node->nodeValue ?? '');
        }

        if (!$node instanceof DOMElement) {
            return '';
        }

        $name = strtolower($node->nodeName);

        if (in_array($name, self::IGNORED, true)) {
            return '';
        }

        if (in_array($name, self::BLOCKS, true)) {
            return ' ' . $this->children($node) . ' ';
        }

        return match ($name) {
            'br' => self::BREAK,
            'strong', 'b' => $this->wrap($this->children($node), '**'),
            'em', 'i' => $this->wrap($this->children($node), '*'),
            'del', 's', 'strike' => $this->wrap($this->children($node), '~~'),
            'code', 'kbd', 'samp', 'tt' => $this->code($node->textContent),
            'a' => $this->link($node),
            'img' => $this->image($node),
            'input' => $this->checkbox($node),
            default => $this->children($node),
        };
    }

    protected function wrap(string $content, string $marker): string
    {
        if (trim(str_replace(self::BREAK, '', $content)) === '') {
            return $content;
        }

        preg_match('/^(\s*)(.*?)(\s*)$/s', $content, $matches);

        return $matches[1] . $marker . $matches[2] . $marker . $matches[3];
    }

    protected function code(string $code): string
    {
        $code = (string) preg_replace('/\s+/', ' ', $code);

        if ($code === '') {
            return '';
        }

        preg_match_all('/`+/', $code, $matches);
        $longest = max(array_map('strlen', $matches[0] ?: ['']));
        $fence = str_repeat('`', $longest + 1);
        $padding = str_starts_with($code, '`') || str_ends_with($code, '`') || (str_starts_with($code, ' ') && str_ends_with($code, ' ') && trim($code) !== '') ? ' ' : '';

        return $fence . $padding . $code . $padding . $fence;
    }

    protected function link(DOMElement $element): string
    {
        $content = trim($this->collapse($this->children($element)));
        $href = trim($element->getAttribute('href'));

        if ($href === '') {
            return $content;
        }

        $title = $element->getAttribute('title');
        $content = $content === '' ? $this->escape($href) : str_replace(self::BREAK . "\n", ' ', $content);

        return '[' . $content . '](' . $this->destination($href) . ($title !== '' ? ' "' . str_replace('"', '\\"', $title) . '"' : '') . ')';
    }

    protected function image(DOMElement $element): string
    {
        $src = trim($element->getAttribute('src'));

        if ($src === '') {
            return '';
        }

        $title = $element->getAttribute('title');
        $alt = str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], (string) preg_replace('/\s+/', ' ', trim($element->getAttribute('alt'))));

        return '![' . $alt . '](' . $this->destination($src) . ($title !== '' ? ' "' . str_replace('"', '\\"', $title) . '"' : '') . ')';
    }

    protected function destination(string $url): string
    {
        $url = str_replace(' ', '%20', $url);

        if (preg_match('/[()<>\s]/', $url) === 1) {
            return '<' . str_replace(['<', '>'], ['%3C', '%3E'], $url) . '>';
        }

        return $url;
    }

    protected function checkbox(DOMElement $element): string
    {
        if (strtolower($element->getAttribute('type')) !== 'checkbox') {
            return '';
        }

        return $element->hasAttribute('checked') ? '[x] ' : '[ ] ';
    }

    protected function escape(string $text): string
    {
        $text = str_replace(self::BREAK, '', $text);
        $text = (string) preg_replace('/([\\\\`*\[\]<~])/', '\\\\$1', $text);

        return (string) preg_replace('/(?<![\p{L}\p{N}])_|_(?![\p{L}\p{N}])/u', '\\_', $text);
    }

    protected function pre(DOMElement $element): string
    {
        $language = '';
        $code = $element;

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'code') {
                $code = $child;
            }
        }

        foreach ([$code, $element] as $candidate) {
            if (preg_match('/(?:^|\s)(?:language|lang)-([^\s]+)/', $candidate->getAttribute('class'), $matches) === 1) {
                $language = $matches[1];

                break;
            }
        }

        $content = rtrim(str_replace(["\r\n", "\r"], "\n", $code->textContent), "\n");
        $content = str_starts_with($content, "\n") ? substr($content, 1) : $content;
        preg_match_all('/^[ \t]*(`{3,}|~{3,})/m', $content, $matches);
        $longest = 2;

        foreach ($matches[1] as $fence) {
            if ($fence[0] === '`') {
                $longest = max($longest, strlen($fence));
            }
        }

        $fence = str_repeat('`', $longest + 1);
        $this->codeBlocks[] = $fence . $language . "\n" . ($content === '' ? '' : $content . "\n") . $fence;

        return "\u{E001}" . (count($this->codeBlocks) - 1) . "\u{E001}";
    }

    protected function quote(string $content): string
    {
        $content = $this->restoreCode($content);

        return $content === '' ? '' : $this->indentLines($content, '> ', '> ');
    }

    protected function listBlock(DOMElement $element): string
    {
        $ordered = strtolower($element->nodeName) === 'ol';
        $number = $ordered && $element->hasAttribute('start') ? (int) $element->getAttribute('start') : 1;
        $items = [];
        $loose = false;

        foreach ($element->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            if (strtolower($child->nodeName) !== 'li') {
                if (in_array(strtolower($child->nodeName), ['ul', 'ol'], true) && $items !== []) {
                    $items[count($items) - 1] .= "\n" . $this->indentLines($this->restoreCode($this->block($child)), '  ', '  ');
                }

                continue;
            }

            $marker = $ordered ? $number . '. ' : '- ';
            $number++;
            $content = $this->restoreCode($this->item($child));

            if (str_contains($content, "\n\n")) {
                $loose = true;
            }

            $items[] = $this->indentLines($content, $marker, str_repeat(' ', strlen($marker)));
        }

        return implode($loose ? "\n\n" : "\n", $items);
    }

    protected function item(DOMElement $item): string
    {
        $paragraphs = false;

        foreach ($item->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->nodeName), ['p', 'pre', 'blockquote', 'table', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                $paragraphs = true;
            }
        }

        if ($paragraphs) {
            return $this->blocks($item);
        }

        $parts = [];
        $inline = '';

        foreach ($item->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->nodeName), self::BLOCKS, true)) {
                $parts[] = $this->paragraph($inline);
                $inline = '';
                $parts[] = $this->block($child);

                continue;
            }

            $inline .= $this->inline($child);
        }

        $parts[] = $this->paragraph($inline);

        return implode("\n", array_filter($parts, static fn (string $part): bool => trim($part) !== ''));
    }

    protected function table(DOMElement $table): string
    {
        $rows = [];
        $aligns = [];

        foreach ($table->getElementsByTagName('tr') as $row) {
            $cells = [];

            foreach ($row->childNodes as $cell) {
                if (!$cell instanceof DOMElement || !in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                    continue;
                }

                if ($rows === []) {
                    $align = strtolower($cell->getAttribute('align'));

                    if ($align === '' && preg_match('/text-align\s*:\s*(left|right|center)/i', $cell->getAttribute('style'), $matches) === 1) {
                        $align = strtolower($matches[1]);
                    }

                    $aligns[] = $align;
                }

                $text = trim(str_replace(self::BREAK . "\n", ' ', $this->collapse($this->children($cell))));
                $cells[] = $text === '' ? ' ' : (string) preg_replace('/(?<!\\\\)\|/', '\\|', $text);
            }

            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return '';
        }

        $columns = max(array_map('count', $rows));
        $lines = [];

        foreach ($rows as $index => $cells) {
            $lines[] = '| ' . implode(' | ', array_pad($cells, $columns, ' ')) . ' |';

            if ($index === 0) {
                $separators = [];

                for ($column = 0; $column < $columns; $column++) {
                    $separators[] = match ($aligns[$column] ?? '') {
                        'left' => ':---',
                        'right' => '---:',
                        'center' => ':---:',
                        default => '---',
                    };
                }

                $lines[] = '| ' . implode(' | ', $separators) . ' |';
            }
        }

        return implode("\n", $lines);
    }

    protected function restoreCode(string $content): string
    {
        return (string) preg_replace_callback('/\x{E001}(\d+)\x{E001}/u', fn (array $matches): string => $this->codeBlocks[(int) $matches[1]], $content);
    }

    protected function indentLines(string $content, string $first, string $rest): string
    {
        $lines = explode("\n", $content);

        foreach ($lines as $index => $line) {
            $prefix = $index === 0 ? $first : $rest;
            $lines[$index] = $line === '' ? rtrim($prefix) : $prefix . $line;
        }

        return implode("\n", $lines);
    }
}