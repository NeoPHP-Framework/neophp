<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Block;

class CodeBlock extends AbstractBlock
{
    public const TYPE = 'code';

    public function __construct(
        protected string $content,
        ?string $title = null,
        protected ?string $language = null,
        protected int $firstLine = 0,
        protected ?int $highlightLine = null,
    ) {
        $this->title = $title;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function getFirstLine(): int
    {
        return $this->firstLine;
    }

    public function getHighlightLine(): ?int
    {
        return $this->highlightLine;
    }
}