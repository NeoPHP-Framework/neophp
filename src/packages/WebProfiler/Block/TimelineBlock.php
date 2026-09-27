<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Block;

use NeoPHP\Package\WebProfiler\Model\TimelineEvent;

class TimelineBlock extends AbstractBlock
{
    public const TYPE = 'timeline';

    protected array $events = [];

    public function __construct(array $events = [], protected ?float $total = null, ?string $title = null)
    {
        $this->title = $title;

        foreach ($events as $event) {
            $this->add($event instanceof TimelineEvent ? $event : TimelineEvent::fromArray((array) $event));
        }
    }

    public function add(TimelineEvent $event): static
    {
        $this->events[] = $event;

        return $this;
    }

    public function getEvents(): array
    {
        return $this->events;
    }

    public function getTotal(): float
    {
        if ($this->total !== null && $this->total > 0) {
            return $this->total;
        }

        $total = 0.0;

        foreach ($this->events as $event) {
            $total = max($total, $event->getEnd());
        }

        return $total;
    }
}