<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Contract;

use NeoPHP\Package\WebProfiler\Renderer\BlockRenderer;

interface BlockRendererInterface
{
    public function getTypes(): array;

    public function render(BlockInterface $block, BlockRenderer $renderer): string;
}