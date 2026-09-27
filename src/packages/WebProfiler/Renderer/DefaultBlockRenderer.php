<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Renderer;

use NeoPHP\Package\WebProfiler\Block\AlertBlock;
use NeoPHP\Package\WebProfiler\Block\CodeBlock;
use NeoPHP\Package\WebProfiler\Block\HtmlBlock;
use NeoPHP\Package\WebProfiler\Block\KeyValueBlock;
use NeoPHP\Package\WebProfiler\Block\MetricBlock;
use NeoPHP\Package\WebProfiler\Block\SectionBlock;
use NeoPHP\Package\WebProfiler\Block\TableBlock;
use NeoPHP\Package\WebProfiler\Block\TabsBlock;
use NeoPHP\Package\WebProfiler\Block\TextBlock;
use NeoPHP\Package\WebProfiler\Block\TimelineBlock;
use NeoPHP\Package\WebProfiler\Contract\BlockInterface;
use NeoPHP\Package\WebProfiler\Contract\BlockRendererInterface;
use NeoPHP\Package\WebProfiler\Util\ValueExporter;

class DefaultBlockRenderer implements BlockRendererInterface
{
    public const METHODS = [
        TableBlock::TYPE => 'table',
        KeyValueBlock::TYPE => 'keyValue',
        MetricBlock::TYPE => 'metric',
        TimelineBlock::TYPE => 'timeline',
        CodeBlock::TYPE => 'code',
        AlertBlock::TYPE => 'alert',
        TextBlock::TYPE => 'text',
        SectionBlock::TYPE => 'section',
        TabsBlock::TYPE => 'tabs',
        HtmlBlock::TYPE => 'html',
    ];

    public function getTypes(): array
    {
        return array_keys(self::METHODS);
    }

    public function render(BlockInterface $block, BlockRenderer $renderer): string
    {
        $method = self::METHODS[$block->getType()] ?? null;

        return $method === null ? '' : $this->{$method}($block, $renderer);
    }

    protected function table(TableBlock $block, BlockRenderer $r): string
    {
        $html = $r->title($block->getTitle());

        if ($block->getRows() === []) {
            return $html . '<p class="neo-empty">' . $r->escape($block->getEmptyMessage()) . '</p>';
        }

        $html .= '<div class="neo-table-wrap"><table class="neo-table"><thead><tr>';

        foreach ($block->getHeaders() as $header) {
            $html .= '<th>' . $r->escape((string) $header) . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($block->getRows() as $row) {
            $html .= '<tr>';

            foreach ((array) $row as $cell) {
                $html .= '<td>' . $r->value($cell) . '</td>';
            }

            $html .= '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    protected function keyValue(KeyValueBlock $block, BlockRenderer $r): string
    {
        $html = $r->title($block->getTitle());

        if ($block->getItems() === []) {
            return $html . '<p class="neo-empty">' . $r->escape($block->getEmptyMessage()) . '</p>';
        }

        $html .= '<div class="neo-table-wrap"><table class="neo-table neo-kv"><tbody>';

        foreach ($block->getItems() as $key => $value) {
            $html .= '<tr><th>' . $r->escape((string) $key) . '</th><td>' . $r->value($value) . '</td></tr>';
        }

        return $html . '</tbody></table></div>';
    }

    protected function metric(MetricBlock $block, BlockRenderer $r): string
    {
        $html = $r->title($block->getTitle()) . '<div class="neo-metrics">';

        foreach ($block->getMetrics() as $metric) {
            $html .= sprintf(
                '<div class="neo-metric neo-status-%s"%s><span class="neo-metric-value">%s%s</span><span class="neo-metric-label">%s</span></div>',
                $r->escape($metric->getStatus()),
                $metric->getHelp() !== null ? ' title="' . $r->escape($metric->getHelp()) . '"' : '',
                $r->escape((string) $metric->getValue()),
                $metric->getUnit() !== null ? ' <small>' . $r->escape($metric->getUnit()) . '</small>' : '',
                $r->escape($metric->getLabel()),
            );
        }

        return $html . '</div>';
    }

    protected function timeline(TimelineBlock $block, BlockRenderer $r): string
    {
        $html = $r->title($block->getTitle());
        $total = $block->getTotal();

        if ($block->getEvents() === [] || $total <= 0) {
            return $html . '<p class="neo-empty">No event.</p>';
        }

        $html .= '<div class="neo-timeline">';

        foreach ($block->getEvents() as $event) {
            $left = max(0.0, min(100.0, $event->getStart() / $total * 100));
            $width = max(0.3, min(100.0 - $left, $event->getDuration() / $total * 100));
            $label = sprintf('%s (%s)', $event->getName(), ValueExporter::formatDuration($event->getDuration()));

            if ($event->getMemory() !== null) {
                $label .= ' / ' . ValueExporter::formatBytes($event->getMemory());
            }

            $html .= sprintf(
                '<div class="neo-timeline-row"><span class="neo-timeline-name" title="%1$s">%1$s</span><span class="neo-timeline-track"><span class="neo-timeline-bar neo-cat-%2$s" style="left:%3$.3F%%;width:%4$.3F%%" title="%5$s"></span></span><span class="neo-timeline-time">%6$s</span></div>',
                $r->escape($event->getName()),
                $r->escape((string) preg_replace('/[^a-z0-9_-]/i', '', $event->getCategory())),
                $left,
                $width,
                $r->escape($label . ' @ ' . ValueExporter::formatDuration($event->getStart())),
                $r->escape(ValueExporter::formatDuration($event->getDuration())),
            );
        }

        return $html . sprintf('<div class="neo-timeline-total">Total: %s</div></div>', $r->escape(ValueExporter::formatDuration($total)));
    }

    protected function code(CodeBlock $block, BlockRenderer $r): string
    {
        $html = $r->title($block->getTitle());
        $language = $block->getLanguage() !== null ? ' data-language="' . $r->escape($block->getLanguage()) . '"' : '';

        if ($block->getFirstLine() <= 0) {
            return $html . '<pre class="neo-code"' . $language . '>' . $r->escape($block->getContent()) . '</pre>';
        }

        $html .= '<pre class="neo-code neo-code-lines"' . $language . '>';

        foreach (preg_split('/\R/', $block->getContent()) ?: [] as $index => $line) {
            $number = $block->getFirstLine() + $index;
            $html .= sprintf(
                '<span class="neo-line%s"><span class="neo-line-number">%d</span>%s</span>' . "\n",
                $number === $block->getHighlightLine() ? ' neo-line-highlight' : '',
                $number,
                $r->escape($line),
            );
        }

        return $html . '</pre>';
    }

    protected function alert(AlertBlock $block, BlockRenderer $r): string
    {
        return sprintf(
            '<div class="neo-alert neo-alert-%s">%s%s</div>',
            $r->escape($block->getStatus()),
            $block->getTitle() !== null ? '<strong>' . $r->escape($block->getTitle()) . '</strong> ' : '',
            nl2br($r->escape($block->getMessage())),
        );
    }

    protected function text(TextBlock $block, BlockRenderer $r): string
    {
        return $r->title($block->getTitle()) . '<p class="neo-text">' . nl2br($r->escape($block->getText())) . '</p>';
    }

    protected function section(SectionBlock $block, BlockRenderer $r): string
    {
        return sprintf(
            '<details class="neo-section"%s><summary>%s</summary><div class="neo-section-body">%s</div></details>',
            $block->isCollapsed() ? '' : ' open',
            $r->escape((string) $block->getTitle()),
            $r->renderBlocks($block->getBlocks()),
        );
    }

    protected function tabs(TabsBlock $block, BlockRenderer $r): string
    {
        $group = $r->uniqueId('neo-tabs');
        $buttons = '';
        $panes = '';
        $index = 0;

        foreach ($block->getTabs() as $label => $blocks) {
            $id = $group . '-' . $index;
            $buttons .= sprintf(
                '<button type="button" class="neo-tab%s" data-tab="%s">%s</button>',
                $index === 0 ? ' active' : '',
                $r->escape($id),
                $r->escape((string) $label),
            );
            $panes .= sprintf('<div class="neo-tab-pane%s" id="%s">%s</div>', $index === 0 ? ' active' : '', $r->escape($id), $r->renderBlocks($blocks));
            $index++;
        }

        return $r->title($block->getTitle()) . sprintf('<div class="neo-tabs" data-tabs="%s"><div class="neo-tab-list">%s</div>%s</div>', $r->escape($group), $buttons, $panes);
    }

    protected function html(HtmlBlock $block, BlockRenderer $r): string
    {
        return $r->title($block->getTitle()) . '<div class="neo-html">' . $block->getHtml() . '</div>';
    }
}