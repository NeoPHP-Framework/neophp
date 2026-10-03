<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Contract;

use NeoPHP\Package\Queue\Envelope;
use NeoPHP\Package\Queue\Serializer\MessageSerializer;
use Throwable;

abstract class AbstractTransport implements TransportInterface
{
    public const DEFAULT_QUEUE = 'default';

    public const DEFAULT_RETRY_AFTER = 90;

    public function __construct(protected string $name, protected MessageSerializer $serializer, protected array $options = [])
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDefaultQueue(): string
    {
        return (string) ($this->options['queue'] ?? self::DEFAULT_QUEUE);
    }

    public function getRetryAfter(): int
    {
        return max(1, (int) ($this->options['retry_after'] ?? self::DEFAULT_RETRY_AFTER));
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    protected function queues(array $queues): array
    {
        $queues = array_values(array_filter(array_map(static fn (mixed $queue): string => trim((string) $queue), $queues), static fn (string $queue): bool => $queue !== ''));

        return $queues === [] ? [$this->getDefaultQueue()] : $queues;
    }

    protected function toRecord(Envelope $envelope): array
    {
        $message = $envelope->getMessage();

        return [
            'id' => (string) $envelope->getId(),
            'queue' => $envelope->getQueue(),
            'class' => $envelope->getMessageClass(),
            'body' => $message !== null ? $this->serializer->encode($message) : (string) $envelope->getMeta('body', ''),
            'attempts' => $envelope->getAttempts(),
            'max_attempts' => $envelope->getMaxAttempts(),
            'priority' => $envelope->getPriority(),
            'available_at' => $envelope->getAvailableAt(),
            'reserved_at' => $envelope->getReservedAt(),
            'created_at' => $envelope->getCreatedAt(),
            'failed_at' => $envelope->getFailedAt(),
            'last_error' => $envelope->getLastError(),
        ];
    }

    protected function fromRecord(array $record, bool $decode = true): Envelope
    {
        $envelope = (new Envelope())
            ->setId((string) ($record['id'] ?? ''))
            ->setTransport($this->name)
            ->setQueue((string) ($record['queue'] ?? $this->getDefaultQueue()))
            ->setMessageClass((string) ($record['class'] ?? $record['message_class'] ?? 'unknown'))
            ->setAttempts((int) ($record['attempts'] ?? 0))
            ->setMaxAttempts((int) ($record['max_attempts'] ?? 1))
            ->setPriority((int) ($record['priority'] ?? 0))
            ->setCreatedAt((int) ($record['created_at'] ?? time()))
            ->setAvailableAt((int) ($record['available_at'] ?? time()))
            ->setReservedAt(isset($record['reserved_at']) ? (int) $record['reserved_at'] : null)
            ->setFailedAt(isset($record['failed_at']) ? (int) $record['failed_at'] : null)
            ->setLastError(isset($record['last_error']) ? (string) $record['last_error'] : (isset($record['error']) ? (string) $record['error'] : null))
            ->setMeta('body', (string) ($record['body'] ?? ''));

        if ($decode) {
            try {
                $envelope->setMessage($this->serializer->decode((string) ($record['body'] ?? '')));
            } catch (Throwable $exception) {
                $envelope->setMeta('decode_error', $exception);
            }
        }

        return $envelope;
    }

    protected function excerpt(string $error): string
    {
        return mb_substr($error, 0, 4000);
    }
}