<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cache\Contract;

use DateInterval;

interface CacheInterface
{
    public function getName(): string;

    public function getAdapter(): AdapterInterface;

    public function get(string $key, ?callable $callback = null, int|DateInterval|null $ttl = null, array $tags = []): mixed;

    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null, array $tags = []): bool;

    public function has(string $key): bool;

    public function delete(string $key): bool;

    public function getMany(array $keys, mixed $default = null): array;

    public function setMany(array $values, int|DateInterval|null $ttl = null, array $tags = []): bool;

    public function deleteMany(array $keys): bool;

    public function clear(): bool;

    public function invalidateTags(array $tags): bool;

    public function prune(): int;
}