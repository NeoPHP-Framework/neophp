<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Transport;

use NeoPHP\Package\Queue\Contract\AbstractTransport;
use NeoPHP\Package\Queue\Envelope;
use NeoPHP\Package\Queue\Handler\HandlerInvoker;
use NeoPHP\Package\Queue\Serializer\MessageSerializer;

class SyncTransport extends AbstractTransport
{
    public function __construct(string $name, MessageSerializer $serializer, protected HandlerInvoker $invoker, array $options = [])
    {
        parent::__construct($name, $serializer, $options);
    }

    public function send(Envelope $envelope): Envelope
    {
        $message = $envelope->getMessage();
        $envelope->setId('sync-' . bin2hex(random_bytes(6)))->setAttempts(1);

        if ($message !== null) {
            $this->invoker->invoke($message);
        }

        return $envelope;
    }

    public function get(array $queues = [], int $limit = 1): array
    {
        return [];
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function release(Envelope $envelope, int $delay = 0, ?string $error = null): void
    {
    }

    public function reject(Envelope $envelope, string $error): void
    {
    }

    public function count(?string $queue = null): array
    {
        return ['ready' => 0, 'delayed' => 0, 'reserved' => 0, 'failed' => 0];
    }

    public function getQueues(): array
    {
        return [];
    }

    public function purge(?string $queue = null): int
    {
        return 0;
    }

    public function getFailed(int $limit = 50): array
    {
        return [];
    }

    public function retryFailed(string $id): bool
    {
        return false;
    }

    public function forgetFailed(string $id): bool
    {
        return false;
    }

    public function flushFailed(): int
    {
        return 0;
    }
}