<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Message;

use NeoPHP\Component\Mailer\Mime\Email;

class SentMessage
{
    protected string $debug = '';

    protected ?string $transportId = null;

    public function __construct(
        protected Email $email,
        protected Envelope $envelope,
        protected string $messageId,
        protected string $raw,
        protected string $transport,
    ) {
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function getEnvelope(): Envelope
    {
        return $this->envelope;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function toString(): string
    {
        return $this->raw;
    }

    public function getTransport(): string
    {
        return $this->transport;
    }

    public function getTransportId(): ?string
    {
        return $this->transportId;
    }

    public function setTransportId(?string $transportId): static
    {
        $this->transportId = $transportId;

        return $this;
    }

    public function getDebug(): string
    {
        return $this->debug;
    }

    public function appendDebug(string $debug): static
    {
        $this->debug .= $debug;

        return $this;
    }
}