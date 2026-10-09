<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Event;

use NeoPHP\Component\Event\Contract\AbstractEvent;
use NeoPHP\Package\Queue\Message\Envelope;
use Throwable;

class WorkerMessageFailedEvent extends AbstractEvent
{
    public function __construct(protected Envelope $envelope, protected Throwable $error, protected bool $willRetry, protected int $retryDelay = 0)
    {
    }

    public function getEnvelope(): Envelope
    {
        return $this->envelope;
    }

    public function getError(): Throwable
    {
        return $this->error;
    }

    public function willRetry(): bool
    {
        return $this->willRetry;
    }

    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }
}