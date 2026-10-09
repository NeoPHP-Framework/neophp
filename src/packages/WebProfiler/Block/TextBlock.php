<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Block;

class TextBlock extends AbstractBlock
{
    public const TYPE = 'text';

    public function __construct(protected string $text, ?string $title = null)
    {
        $this->title = $title;
    }

    public function getText(): string
    {
        return $this->text;
    }
}