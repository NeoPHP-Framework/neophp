<?php

declare(strict_types=1);

namespace NeoPHP\Package\Markdown\Document;

use NeoPHP\Package\Markdown\Parser\HtmlRenderer;

class Section
{
    protected ?string $html = null;

    public function __construct(protected Heading $heading, protected string $markdown, protected HtmlRenderer $renderer)
    {
    }

    public function getTitle(): string
    {
        return $this->heading->getText();
    }

    public function getId(): string
    {
        return $this->heading->getId();
    }

    public function getLevel(): int
    {
        return $this->heading->getLevel();
    }

    public function getHeading(): Heading
    {
        return $this->heading;
    }

    public function getMarkdown(): string
    {
        return $this->markdown;
    }

    public function toHtml(): string
    {
        return $this->html ??= $this->renderer->render($this->markdown)['html'];
    }
}