<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cache\Contract;

interface AdapterInterface
{
    public function getName(): string;

    public function getNamespace(): string;

    public function fetch(string $id): ?string;

    public function fetchMany(array $ids): array;

    public function save(string $id, string $data, ?int $expiresAt): bool;

    public function remove(string $id): bool;

    public function contains(string $id): bool;

    public function clear(): bool;

    public function prune(): int;

    public function lock(string $id, float $timeout): bool;

    public function unlock(string $id): void;
}