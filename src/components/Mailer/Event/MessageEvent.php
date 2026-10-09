<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Event;

use NeoPHP\Component\Event\Contract\AbstractEvent;
use NeoPHP\Component\Mailer\Message\Envelope;
use NeoPHP\Component\Mailer\Mime\Email;

class MessageEvent extends AbstractEvent
{
    protected bool $rejected = false;

    protected bool $envelopeChanged = false;

    public function __construct(protected Email $email, protected Envelope $envelope, protected string $transport)
    {
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function setEmail(Email $email): void
    {
        $this->email = $email;
    }

    public function getEnvelope(): Envelope
    {
        return $this->envelope;
    }

    public function setEnvelope(Envelope $envelope): void
    {
        $this->envelope = $envelope;
        $this->envelopeChanged = true;
    }

    public function isEnvelopeChanged(): bool
    {
        return $this->envelopeChanged;
    }

    public function getTransport(): string
    {
        return $this->transport;
    }

    public function reject(): void
    {
        $this->rejected = true;
        $this->stopPropagation();
    }

    public function isRejected(): bool
    {
        return $this->rejected;
    }
}