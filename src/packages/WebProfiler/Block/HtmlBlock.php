<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Block;

class HtmlBlock extends AbstractBlock
{
    public const TYPE = 'html';

    public function __construct(protected string $html, ?string $title = null)
    {
        $this->title = $title;
    }

    public function getHtml(): string
    {
        return $this->html;
    }
}