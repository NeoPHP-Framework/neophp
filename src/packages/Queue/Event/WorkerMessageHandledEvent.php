<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Event;

use NeoPHP\Component\Event\Contract\AbstractEvent;
use NeoPHP\Package\Queue\Envelope;

class WorkerMessageHandledEvent extends AbstractEvent
{
    public function __construct(protected Envelope $envelope, protected float $duration)
    {
    }

    public function getEnvelope(): Envelope
    {
        return $this->envelope;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }
}