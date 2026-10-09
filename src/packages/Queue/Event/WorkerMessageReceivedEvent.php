<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Event;

use NeoPHP\Component\Event\Contract\AbstractEvent;
use NeoPHP\Package\Queue\Message\Envelope;

class WorkerMessageReceivedEvent extends AbstractEvent
{
    public function __construct(protected Envelope $envelope)
    {
    }

    public function getEnvelope(): Envelope
    {
        return $this->envelope;
    }
}