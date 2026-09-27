<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Stopwatch;

class Stopwatch
{
    protected array $events = [];

    protected float $origin;

    public function __construct(?float $origin = null)
    {
        $this->origin = $origin ?? (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    }

    public function reset(?float $origin = null): static
    {
        $this->events = [];
        $this->origin = $origin ?? microtime(true);

        return $this;
    }

    public function getOrigin(): float
    {
        return $this->origin;
    }

    public function start(string $name, string $category = 'default', ?float $at = null): StopwatchEvent
    {
        return $this->event($name, $category)->start($at);
    }

    public function stop(string $name, ?float $at = null): ?StopwatchEvent
    {
        return isset($this->events[$name]) ? $this->events[$name]->stop($at) : null;
    }

    public function lap(string $name, ?float $at = null): ?StopwatchEvent
    {
        return isset($this->events[$name]) ? $this->events[$name]->lap($at) : null;
    }

    public function measure(string $name, callable $callback, string $category = 'default'): mixed
    {
        $this->start($name, $category);

        try {
            return $callback();
        } finally {
            $this->stop($name);
        }
    }

    public function isStarted(string $name): bool
    {
        return isset($this->events[$name]) && $this->events[$name]->isStarted();
    }

    public function has(string $name): bool
    {
        return isset($this->events[$name]);
    }

    public function getEvent(string $name): ?StopwatchEvent
    {
        return $this->events[$name] ?? null;
    }

    public function getEvents(): array
    {
        return $this->events;
    }

    public function getElapsed(): float
    {
        return (microtime(true) - $this->origin) * 1000;
    }

    public function toArray(): array
    {
        $events = [];

        foreach ($this->events as $event) {
            $events[] = $event->ensureStopped()->toArray();
        }

        usort($events, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $events;
    }

    protected function event(string $name, string $category): StopwatchEvent
    {
        return $this->events[$name] ??= new StopwatchEvent($name, $category, $this->origin);
    }
}