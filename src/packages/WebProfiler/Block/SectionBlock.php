<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Block;

use NeoPHP\Package\WebProfiler\Model\Panel;

class SectionBlock extends AbstractBlock
{
    public const TYPE = 'section';

    protected array $blocks;

    public function __construct(string $title, array $blocks = [], protected bool $collapsed = false)
    {
        $this->title = $title;
        $this->blocks = Panel::assertBlocks($blocks);
    }

    public function getBlocks(): array
    {
        return $this->blocks;
    }

    public function isCollapsed(): bool
    {
        return $this->collapsed;
    }
}