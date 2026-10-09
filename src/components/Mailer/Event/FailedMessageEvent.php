<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Event;

use NeoPHP\Component\Event\Contract\AbstractEvent;
use NeoPHP\Component\Mailer\Message\Envelope;
use NeoPHP\Component\Mailer\Mime\Email;
use Throwable;

class FailedMessageEvent extends AbstractEvent
{
    public function __construct(protected Email $email, protected Envelope $envelope, protected Throwable $error)
    {
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function getEnvelope(): Envelope
    {
        return $this->envelope;
    }

    public function getError(): Throwable
    {
        return $this->error;
    }
}