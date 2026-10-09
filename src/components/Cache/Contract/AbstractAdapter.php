<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cache\Contract;

abstract class AbstractAdapter implements AdapterInterface
{
    public const LOCK_RETRY_DELAY = 20000;

    protected string $namespace = '';

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function fetchMany(array $ids): array
    {
        $values = [];

        foreach ($ids as $id) {
            $data = $this->fetch((string) $id);

            if ($data !== null) {
                $values[(string) $id] = $data;
            }
        }

        return $values;
    }

    public function contains(string $id): bool
    {
        return $this->fetch($id) !== null;
    }

    public function prune(): int
    {
        return 0;
    }

    public function lock(string $id, float $timeout): bool
    {
        return true;
    }

    public function unlock(string $id): void
    {
    }

    protected function isExpired(?int $expiresAt): bool
    {
        return $expiresAt !== null && $expiresAt > 0 && $expiresAt <= time();
    }

    protected function waitFor(callable $attempt, float $timeout): bool
    {
        $deadline = microtime(true) + max(0.0, $timeout);

        do {
            if ($attempt()) {
                return true;
            }

            usleep(self::LOCK_RETRY_DELAY);
        } while (microtime(true) < $deadline);

        return false;
    }
}