<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Block;

use NeoPHP\Package\WebProfiler\Model\Metric;

class MetricBlock extends AbstractBlock
{
    public const TYPE = 'metric';

    protected array $metrics = [];

    public function __construct(array $metrics = [], ?string $title = null)
    {
        $this->title = $title;

        foreach ($metrics as $metric) {
            $this->add($metric);
        }
    }

    public function add(Metric $metric): static
    {
        $this->metrics[] = $metric;

        return $this;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }
}