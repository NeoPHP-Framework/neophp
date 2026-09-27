<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Renderer;

use NeoPHP\Package\WebProfiler\Contract\BlockInterface;
use NeoPHP\Package\WebProfiler\Contract\BlockRendererInterface;
use NeoPHP\Package\WebProfiler\Model\Panel;
use NeoPHP\Package\WebProfiler\Util\ValueExporter;

class BlockRenderer
{
    protected array $renderers = [];

    protected int $sequence = 0;

    public function __construct(iterable $renderers = [])
    {
        $this->register(new DefaultBlockRenderer());

        foreach ($renderers as $renderer) {
            $this->register($renderer);
        }
    }

    public function register(BlockRendererInterface $renderer): static
    {
        foreach ($renderer->getTypes() as $type) {
            $this->renderers[(string) $type] = $renderer;
        }

        return $this;
    }

    public function supports(string $type): bool
    {
        return isset($this->renderers[$type]);
    }

    public function getTypes(): array
    {
        return array_keys($this->renderers);
    }

    public function renderPanel(Panel $panel): string
    {
        return $this->renderBlocks($panel->getBlocks());
    }

    public function renderBlocks(array $blocks): string
    {
        $html = '';

        foreach ($blocks as $block) {
            $html .= $this->render($block);
        }

        return $html;
    }

    public function render(BlockInterface $block): string
    {
        $renderer = $this->renderers[$block->getType()] ?? null;

        if ($renderer === null) {
            return '<div class="neo-alert neo-alert-warning">' . $this->escape(sprintf('No renderer registered for the block type "%s" (%s).', $block->getType(), $block::class)) . '</div>';
        }

        return $renderer->render($block, $this);
    }

    public function escape(mixed $value): string
    {
        return htmlspecialchars(is_string($value) ? $value : ValueExporter::toString($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function value(mixed $value): string
    {
        if ($value instanceof BlockInterface) {
            return $this->render($value);
        }

        if ($value === null || is_bool($value)) {
            return '<span class="neo-muted">' . ($value === null ? 'null' : ($value ? 'true' : 'false')) . '</span>';
        }

        if (is_scalar($value)) {
            return $this->escape((string) $value);
        }

        return '<pre class="neo-dump">' . $this->escape(ValueExporter::toString($value)) . '</pre>';
    }

    public function title(?string $title, string $tag = 'h3'): string
    {
        return $title === null || $title === '' ? '' : sprintf('<%1$s class="neo-block-title">%2$s</%1$s>', $tag, $this->escape($title));
    }

    public function uniqueId(string $prefix = 'neo'): string
    {
        return $prefix . '-' . (++$this->sequence);
    }
}