<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Stopwatch;

class StopwatchEvent
{
    protected array $periods = [];

    protected array $started = [];

    public function __construct(protected string $name, protected string $category, protected float $origin)
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function start(?float $at = null): static
    {
        $this->started[] = $this->now($at);

        return $this;
    }

    public function stop(?float $at = null): static
    {
        if ($this->started !== []) {
            $this->periods[] = [array_pop($this->started), $this->now($at), memory_get_usage(true)];
        }

        return $this;
    }

    public function lap(?float $at = null): static
    {
        return $this->stop($at)->start($at);
    }

    public function isStarted(): bool
    {
        return $this->started !== [];
    }

    public function ensureStopped(): static
    {
        while ($this->started !== []) {
            $this->stop();
        }

        return $this;
    }

    public function getPeriods(): array
    {
        return array_map(static fn (array $period): array => ['start' => $period[0], 'end' => $period[1], 'duration' => $period[1] - $period[0], 'memory' => $period[2]], $this->periods);
    }

    public function getStart(): float
    {
        return $this->periods === [] ? ($this->started[0] ?? 0.0) : min(array_column($this->periods, 0));
    }

    public function getEnd(): float
    {
        return $this->periods === [] ? 0.0 : max(array_column($this->periods, 1));
    }

    public function getDuration(): float
    {
        return array_sum(array_map(static fn (array $period): float => $period[1] - $period[0], $this->periods));
    }

    public function getMemory(): int
    {
        return $this->periods === [] ? 0 : (int) max(array_column($this->periods, 2));
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'category' => $this->category,
            'start' => round($this->getStart(), 3),
            'duration' => round($this->getDuration(), 3),
            'memory' => $this->getMemory(),
            'periods' => count($this->periods),
        ];
    }

    protected function now(?float $at): float
    {
        return (($at ?? microtime(true)) - $this->origin) * 1000;
    }
}