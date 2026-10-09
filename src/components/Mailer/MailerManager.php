<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer;

use NeoPHP\Component\Event\EventManagerInterface;
use NeoPHP\Component\Kernel\Attribute\Component;
use NeoPHP\Component\Mailer\Contract\TransportInterface;
use NeoPHP\Component\Mailer\Event\FailedMessageEvent;
use NeoPHP\Component\Mailer\Event\MessageEvent;
use NeoPHP\Component\Mailer\Event\SentMessageEvent;
use NeoPHP\Component\Mailer\Message\Envelope;
use NeoPHP\Component\Mailer\Message\SentMessage;
use NeoPHP\Component\Mailer\Mime\Address;
use NeoPHP\Component\Mailer\Mime\Email;
use NeoPHP\Component\Mailer\Provider\MailerProvider;
use Throwable;

#[Component(provider: MailerProvider::class)]
final class MailerManager implements MailerManagerInterface
{
    public const DEFAULT_CONFIG = [
        'dsn' => 'null://null',
        'from' => null,
        'envelope' => [
            'sender' => null,
            'recipients' => [],
        ],
        'headers' => [],
    ];

    protected TransportInterface $transport;

    protected ?EventManagerInterface $events = null;

    protected array $config = self::DEFAULT_CONFIG;

    public function __construct(TransportInterface $transport, ?EventManagerInterface $events = null, array $config = [])
    {
        $this->transport = $transport;
        $this->events = $events;
        $this->config = array_replace_recursive(self::DEFAULT_CONFIG, $config);
    }

    public function send(Email $email, ?Envelope $envelope = null): ?SentMessage
    {
        $email = clone $email;
        $this->applyDefaults($email);
        $email->validate();
        $explicit = $envelope !== null;
        $envelope ??= Envelope::create($email);

        if ($this->events !== null) {
            $event = $this->events->dispatch(new MessageEvent($email, $envelope, (string) $this->transport));

            if ($event->isRejected()) {
                return null;
            }

            $email = $event->getEmail();
            $email->validate();
            $envelope = $explicit || $event->isEnvelopeChanged() ? $event->getEnvelope() : Envelope::create($email);
        }

        $envelope = $this->redirect($envelope);

        try {
            $message = $this->transport->send($email, $envelope);
        } catch (Throwable $exception) {
            $this->events?->dispatch(new FailedMessageEvent($email, $envelope, $exception));

            throw $exception;
        }

        $this->events?->dispatch(new SentMessageEvent($message));

        return $message;
    }

    public function getTransport(): TransportInterface
    {
        return $this->transport;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    protected function applyDefaults(Email $email): void
    {
        $from = $this->config['from'] ?? null;

        if ($email->getFrom() === [] && $from !== null && $from !== '' && $from !== []) {
            $email->from(is_array($from) ? new Address((string) ($from['address'] ?? ''), (string) ($from['name'] ?? '')) : Address::create((string) $from));
        }

        $existing = array_map(static fn (array $header): string => strtolower($header[0]), $email->getHeaders());

        foreach ((array) ($this->config['headers'] ?? []) as $name => $value) {
            if (!in_array(strtolower((string) $name), $existing, true)) {
                $email->addHeader((string) $name, (string) $value);
            }
        }
    }

    protected function redirect(Envelope $envelope): Envelope
    {
        $sender = $this->config['envelope']['sender'] ?? null;
        $recipients = array_values(array_filter((array) ($this->config['envelope']['recipients'] ?? []), static fn (mixed $recipient): bool => is_string($recipient) && trim($recipient) !== ''));

        if ((is_string($sender) && $sender !== '') || $recipients !== []) {
            $envelope = clone $envelope;
        }

        if (is_string($sender) && $sender !== '') {
            $envelope->setSender($sender);
        }

        if ($recipients !== []) {
            $envelope->setRecipients($recipients);
        }

        return $envelope;
    }
}