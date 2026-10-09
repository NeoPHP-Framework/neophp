<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Block;

use NeoPHP\Package\WebProfiler\Model\Panel;

class TabsBlock extends AbstractBlock
{
    public const TYPE = 'tabs';

    protected array $tabs = [];

    public function __construct(array $tabs = [], ?string $title = null)
    {
        $this->title = $title;

        foreach ($tabs as $label => $blocks) {
            $this->addTab((string) $label, (array) $blocks);
        }
    }

    public function addTab(string $label, array $blocks): static
    {
        $this->tabs[$label] = Panel::assertBlocks($blocks);

        return $this;
    }

    public function getTabs(): array
    {
        return $this->tabs;
    }
}