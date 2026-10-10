<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer;

use NeoPHP\Component\Mailer\Contract\TransportInterface;
use NeoPHP\Component\Mailer\Exception\MailerException;
use NeoPHP\Component\Mailer\Exception\TransportException;
use NeoPHP\Component\Mailer\Message\Envelope;
use NeoPHP\Component\Mailer\Message\SentMessage;
use NeoPHP\Component\Mailer\Mime\Email;

interface MailerManagerInterface
{
    /**
     * Sends a copy of an email with the defaults of the configuration; MessageEvent, SentMessageEvent and FailedMessageEvent are dispatched.
     *
     * @param Email $email The email
     * @param Envelope|null $envelope Sender and recipients of the SMTP envelope, built from the email when null
     * @return SentMessage|null The sent message, or null when a listener of MessageEvent rejected it
     * @throws MailerException When the email has no sender, no recipient or no body
     * @throws TransportException When the transport cannot send the email
     */
    public function send(Email $email, ?Envelope $envelope = null): ?SentMessage;

    /**
     * Returns the transport sending the emails.
     *
     * @return TransportInterface The transport
     */
    public function getTransport(): TransportInterface;

    /**
     * Returns the configuration of mailer.yaml with its defaults: dsn, from, envelope and headers.
     *
     * @return array<string, mixed> The configuration
     */
    public function getConfig(): array;
}