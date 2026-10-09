<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Event;

use NeoPHP\Component\Event\Contract\AbstractEvent;
use NeoPHP\Package\Queue\Message\Envelope;

class MessageDispatchedEvent extends AbstractEvent
{
    public function __construct(protected Envelope $envelope, protected bool $handledSync = false)
    {
    }

    public function getEnvelope(): Envelope
    {
        return $this->envelope;
    }

    public function getMessage(): ?object
    {
        return $this->envelope->getMessage();
    }

    public function isHandledSync(): bool
    {
        return $this->handledSync;
    }
}