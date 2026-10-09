<?php

declare(strict_types=1);

namespace NeoPHP\Package\Markdown\Document;

use NeoPHP\Package\Markdown\Parser\HtmlRenderer;

class MarkdownDocument
{
    protected array $result;

    protected ?array $headings = null;

    protected ?array $sections = null;

    public function __construct(protected string $source, protected HtmlRenderer $renderer)
    {
        $this->result = $renderer->render($source);
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function toHtml(): string
    {
        return $this->result['html'];
    }

    public function getTitle(): ?string
    {
        foreach ($this->getHeadings() as $heading) {
            if ($heading->getLevel() === 1) {
                return $heading->getText();
            }
        }

        return null;
    }

    public function getDescription(): ?string
    {
        $afterTitle = false;

        foreach ($this->result['outline'] as $block) {
            if ($block['type'] === 'heading') {
                if ($afterTitle) {
                    return null;
                }

                $afterTitle = $block['level'] === 1;

                continue;
            }

            if ($afterTitle && $block['type'] === 'paragraph' && $block['text'] !== '') {
                return $block['text'];
            }
        }

        return null;
    }

    public function getHeadings(): array
    {
        if ($this->headings === null) {
            $this->headings = [];

            foreach ($this->result['headings'] as $heading) {
                $this->headings[] = new Heading($heading['level'], $heading['text'], $heading['id']);
            }
        }

        return $this->headings;
    }

    public function getSummary(): array
    {
        $root = ['level' => 0, 'children' => []];
        $stack = [&$root];

        foreach ($this->getHeadings() as $heading) {
            $item = ['level' => $heading->getLevel(), 'text' => $heading->getText(), 'id' => $heading->getId(), 'children' => []];

            while (count($stack) > 1 && $stack[count($stack) - 1]['level'] >= $item['level']) {
                array_pop($stack);
            }

            $parent = &$stack[count($stack) - 1];
            $parent['children'][] = $item;
            $stack[] = &$parent['children'][count($parent['children']) - 1];
            unset($parent);
        }

        return $root['children'];
    }

    public function getSections(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }

        $this->sections = [];
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $this->source));
        $outline = array_values(array_filter($this->result['outline'], static fn (array $block): bool => $block['type'] === 'heading'));

        foreach ($outline as $index => $block) {
            if ($block['level'] !== 2) {
                continue;
            }

            $end = count($lines) - 1;

            for ($next = $index + 1; $next < count($outline); $next++) {
                if ($outline[$next]['level'] <= 2) {
                    $end = $outline[$next]['start'] - 1;

                    break;
                }
            }

            $content = implode("\n", array_slice($lines, $block['end'] + 1, max(0, $end - $block['end'])));
            $content = rtrim((string) preg_replace('/^(?:[ \t]*\n)+/', '', $content));
            $heading = new Heading(2, $block['text'], $block['id']);

            if (!isset($this->sections[$block['text']])) {
                $this->sections[$block['text']] = new Section($heading, $content === '' ? '' : $content . "\n", $this->renderer);
            }
        }

        return $this->sections;
    }

    public function getSection(string $title): ?Section
    {
        $sections = $this->getSections();

        if (isset($sections[$title])) {
            return $sections[$title];
        }

        $title = mb_strtolower(trim($title), 'UTF-8');

        foreach ($sections as $section) {
            if (mb_strtolower($section->getTitle(), 'UTF-8') === $title || $section->getId() === ltrim($title, '#')) {
                return $section;
            }
        }

        return null;
    }

    public function getLinks(): array
    {
        return $this->result['links'];
    }

    public function getImages(): array
    {
        return $this->result['images'];
    }

    public function getCodeBlocks(): array
    {
        return $this->result['code'];
    }
}