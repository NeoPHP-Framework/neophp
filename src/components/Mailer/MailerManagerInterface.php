<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer;

use NeoPHP\Component\Mailer\Contract\TransportInterface;
use NeoPHP\Component\Mailer\Message\Envelope;
use NeoPHP\Component\Mailer\Message\SentMessage;
use NeoPHP\Component\Mailer\Mime\Email;

interface MailerManagerInterface
{
    public function send(Email $email, ?Envelope $envelope = null): ?SentMessage;

    public function getTransport(): TransportInterface;

    public function getConfig(): array;
}