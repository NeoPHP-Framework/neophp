<?php

declare(strict_types=1);

namespace NeoPHP\Package\Markdown\Parser;

class HtmlRenderer
{
    protected BlockParser $blockParser;

    protected InlineParser $inlineParser;

    protected array $headings = [];

    protected array $code = [];

    protected array $slugs = [];

    protected bool $escapeHtml = false;

    public function __construct(?BlockParser $blockParser = null, ?InlineParser $inlineParser = null)
    {
        $this->blockParser = $blockParser ?? new BlockParser();
        $this->inlineParser = $inlineParser ?? new InlineParser();
    }

    public function setEscapeHtml(bool $escapeHtml): static
    {
        $this->escapeHtml = $escapeHtml;
        $this->inlineParser->setEscapeHtml($escapeHtml);

        return $this;
    }

    public function render(string $markdown): array
    {
        [$blocks, $references] = $this->blockParser->parse($markdown);
        $this->inlineParser->setReferences($references);
        $this->inlineParser->reset();
        $this->headings = [];
        $this->code = [];
        $this->slugs = [];
        $html = '';
        $outline = [];

        foreach ($blocks as $block) {
            $headingCount = count($this->headings);
            $rendered = $this->renderBlock($block, false);
            $html .= $rendered;

            if ($block['type'] === 'heading') {
                $outline[] = ($this->headings[$headingCount] ?? []) + ['type' => 'heading', 'start' => $block['start'], 'end' => $block['end']];
            } elseif ($block['type'] === 'paragraph') {
                $outline[] = ['type' => 'paragraph', 'text' => self::plain($rendered), 'start' => $block['start'], 'end' => $block['end']];
            } else {
                $outline[] = ['type' => $block['type'], 'start' => $block['start'], 'end' => $block['end']];
            }
        }

        return [
            'html' => $html,
            'headings' => $this->headings,
            'outline' => $outline,
            'links' => $this->inlineParser->getLinks(),
            'images' => $this->inlineParser->getImages(),
            'code' => $this->code,
            'references' => $references,
        ];
    }

    public static function slug(string $text): string
    {
        $slug = mb_strtolower(trim($text), 'UTF-8');
        $slug = (string) preg_replace('/[^\p{L}\p{M}\p{N}\p{Pc} \-]/u', '', $slug);

        return str_replace(' ', '-', $slug);
    }

    public static function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    protected function renderBlocks(array $blocks, bool $tight): string
    {
        $html = '';

        foreach ($blocks as $block) {
            $html .= $this->renderBlock($block, $tight);
        }

        return $html;
    }

    protected function renderBlock(array $block, bool $tight): string
    {
        return match ($block['type']) {
            'heading' => $this->heading($block),
            'paragraph' => $this->paragraph($block, $tight),
            'code' => $this->codeBlock($block),
            'html' => $this->escapeHtml ? '<p>' . InlineParser::escape(rtrim($block['html'])) . "</p>\n" : $block['html'],
            'hr' => "<hr />\n",
            'blockquote' => "<blockquote>\n" . $this->renderBlocks($block['children'], false) . "</blockquote>\n",
            'list' => $this->listBlock($block),
            'table' => $this->table($block),
            default => '',
        };
    }

    protected function heading(array $block): string
    {
        $content = $this->inlineParser->parse($block['text']);
        $text = self::plain($content);
        $id = $this->uniqueSlug(self::slug($text));
        $this->headings[] = ['level' => $block['level'], 'text' => $text, 'id' => $id];

        return sprintf("<h%d id=\"%s\">%s</h%1\$d>\n", $block['level'], InlineParser::escape($id), $content);
    }

    protected function uniqueSlug(string $slug): string
    {
        $slug = $slug === '' ? 'section' : $slug;

        if (!isset($this->slugs[$slug])) {
            $this->slugs[$slug] = 0;

            return $slug;
        }

        do {
            $candidate = $slug . '-' . ++$this->slugs[$slug];
        } while (isset($this->slugs[$candidate]));

        $this->slugs[$candidate] = 0;

        return $candidate;
    }

    protected function paragraph(array $block, bool $tight): string
    {
        $content = ($block['prefix'] ?? '') . $this->inlineParser->parse($block['text']);

        return $tight ? $content . "\n" : '<p>' . $content . "</p>\n";
    }

    protected function codeBlock(array $block): string
    {
        $language = $block['language'];
        $this->code[] = ['language' => $language, 'code' => $block['code']];
        $class = $language !== null && $language !== '' ? ' class="language-' . InlineParser::escape($language) . '"' : '';

        return '<pre><code' . $class . '>' . InlineParser::escape($block['code']) . "</code></pre>\n";
    }

    protected function listBlock(array $block): string
    {
        $tag = $block['ordered'] ? 'ol' : 'ul';
        $start = $block['ordered'] && $block['number'] !== 1 ? ' start="' . $block['number'] . '"' : '';
        $tight = !$block['loose'];
        $html = '<' . $tag . $start . ">\n";

        foreach ($block['items'] as $item) {
            $children = $item['children'];
            $checkbox = $item['task'] === null ? '' : ($item['task'] ? '<input type="checkbox" checked="" disabled="" /> ' : '<input type="checkbox" disabled="" /> ');

            if ($checkbox !== '' && isset($children[0]) && $children[0]['type'] === 'paragraph') {
                $children[0]['prefix'] = $checkbox;
            }

            if ($children === []) {
                $html .= "<li>" . $checkbox . "</li>\n";

                continue;
            }

            $content = $this->renderBlocks($children, $tight);

            if (!$tight || $children[0]['type'] !== 'paragraph') {
                $content = "\n" . $content;
            }

            if ($tight && $children[count($children) - 1]['type'] === 'paragraph') {
                $content = rtrim($content, "\n");
            }

            $html .= '<li>' . $content . "</li>\n";
        }

        return $html . '</' . $tag . ">\n";
    }

    protected function table(array $block): string
    {
        $html = "<table>\n<thead>\n" . $this->tableRow($block['head'], $block['aligns'], 'th') . "</thead>\n";

        if ($block['rows'] !== []) {
            $html .= "<tbody>\n";

            foreach ($block['rows'] as $row) {
                $html .= $this->tableRow($row, $block['aligns'], 'td');
            }

            $html .= "</tbody>\n";
        }

        return $html . "</table>\n";
    }

    protected function tableRow(array $cells, array $aligns, string $tag): string
    {
        $html = "<tr>\n";

        foreach ($cells as $index => $cell) {
            $align = $aligns[$index] ?? null;
            $html .= '<' . $tag . ($align !== null ? ' align="' . $align . '"' : '') . '>' . $this->inlineParser->parse($cell) . '</' . $tag . ">\n";
        }

        return $html . "</tr>\n";
    }
}