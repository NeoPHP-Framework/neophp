<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Contract;

use NeoPHP\Package\Queue\Envelope;

interface TransportInterface
{
    public function getName(): string;

    public function getDefaultQueue(): string;

    public function send(Envelope $envelope): Envelope;

    public function get(array $queues = [], int $limit = 1): array;

    public function ack(Envelope $envelope): void;

    public function release(Envelope $envelope, int $delay = 0, ?string $error = null): void;

    public function reject(Envelope $envelope, string $error): void;

    public function count(?string $queue = null): array;

    public function getQueues(): array;

    public function purge(?string $queue = null): int;

    public function getFailed(int $limit = 50): array;

    public function retryFailed(string $id): bool;

    public function forgetFailed(string $id): bool;

    public function flushFailed(): int;
}