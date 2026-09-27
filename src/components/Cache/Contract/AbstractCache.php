<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cache\Contract;

use DateInterval;
use DateTimeImmutable;
use NeoPHP\Component\Cache\Exception\InvalidArgumentException;
use NeoPHP\Component\Cache\Item\CacheItem;

abstract class AbstractCache implements CacheInterface
{
    public const RESERVED_CHARACTERS = '{}()/\\@:';

    public const DEFAULT_LOCK_TIMEOUT = 5.0;

    public const TAG_PREFIX = 'tag:';

    protected string $name;

    protected AdapterInterface $adapter;

    protected ?int $defaultTtl = null;

    protected float $lockTimeout = self::DEFAULT_LOCK_TIMEOUT;

    public function getName(): string
    {
        return $this->name;
    }

    public function getAdapter(): AdapterInterface
    {
        return $this->adapter;
    }

    public function getDefaultTtl(): ?int
    {
        return $this->defaultTtl;
    }

    public function get(string $key, ?callable $callback = null, int|DateInterval|null $ttl = null, array $tags = []): mixed
    {
        $this->validateKey($key);
        $found = false;
        $value = $this->read($key, $found);

        if ($found || $callback === null) {
            return $found ? $value : null;
        }

        $locked = $this->adapter->lock($key, $this->lockTimeout);

        try {
            $value = $this->read($key, $found);

            if ($found) {
                return $value;
            }

            $item = new CacheItem($key, $ttl, $tags);
            $value = $callback($item);
            $this->set($key, $value, $item->getTtl(), $item->getTags());

            return $value;
        } finally {
            if ($locked) {
                $this->adapter->unlock($key);
            }
        }
    }

    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null, array $tags = []): bool
    {
        $this->validateKey($key);
        $expiresAt = $this->expiresAt($ttl);

        if ($expiresAt !== null && $expiresAt <= time()) {
            return $this->adapter->remove($key);
        }

        return $this->adapter->save($key, serialize(['v' => $value, 't' => $this->tagVersions($tags)]), $expiresAt);
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);
        $found = false;
        $this->read($key, $found);

        return $found;
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);

        return $this->adapter->remove($key);
    }

    public function getMany(array $keys, mixed $default = null): array
    {
        $keys = array_map('strval', array_values($keys));
        array_walk($keys, fn (string $key) => $this->validateKey($key));
        $data = $this->adapter->fetchMany($keys);
        $values = [];

        foreach ($keys as $key) {
            $found = false;
            $value = isset($data[$key]) ? $this->decode($data[$key], $found) : null;
            $values[$key] = $found ? $value : $default;
        }

        return $values;
    }

    public function setMany(array $values, int|DateInterval|null $ttl = null, array $tags = []): bool
    {
        foreach (array_keys($values) as $key) {
            $this->validateKey((string) $key);
        }

        $success = true;

        foreach ($values as $key => $value) {
            $success = $this->set((string) $key, $value, $ttl, $tags) && $success;
        }

        return $success;
    }

    public function deleteMany(array $keys): bool
    {
        $success = true;

        foreach ($keys as $key) {
            $success = $this->delete((string) $key) && $success;
        }

        return $success;
    }

    public function clear(): bool
    {
        return $this->adapter->clear();
    }

    public function invalidateTags(array $tags): bool
    {
        $success = true;

        foreach ($tags as $tag) {
            $this->validateKey((string) $tag, 'tag');
            $success = $this->adapter->save(self::TAG_PREFIX . $tag, $this->newVersion(), null) && $success;
        }

        return $success;
    }

    public function prune(): int
    {
        return $this->adapter->prune();
    }

    protected function read(string $key, bool &$found): mixed
    {
        $found = false;
        $data = $this->adapter->fetch($key);

        return $data === null ? null : $this->decode($data, $found);
    }

    protected function decode(string $data, bool &$found): mixed
    {
        $found = false;
        $payload = @unserialize($data);

        if (!is_array($payload) || !array_key_exists('v', $payload)) {
            return null;
        }

        $tags = (array) ($payload['t'] ?? []);

        if ($tags !== []) {
            $current = $this->adapter->fetchMany(array_map(static fn (string $tag): string => self::TAG_PREFIX . $tag, array_map('strval', array_keys($tags))));

            foreach ($tags as $tag => $version) {
                if (($current[self::TAG_PREFIX . $tag] ?? null) !== $version) {
                    return null;
                }
            }
        }

        $found = true;

        return $payload['v'];
    }

    protected function tagVersions(array $tags): array
    {
        if ($tags === []) {
            return [];
        }

        $tags = array_values(array_unique(array_map('strval', $tags)));
        $ids = [];

        foreach ($tags as $tag) {
            $this->validateKey($tag, 'tag');
            $ids[$tag] = self::TAG_PREFIX . $tag;
        }

        $current = $this->adapter->fetchMany(array_values($ids));
        $versions = [];

        foreach ($ids as $tag => $id) {
            if (!isset($current[$id])) {
                $current[$id] = $this->newVersion();
                $this->adapter->save($id, $current[$id], null);
            }

            $versions[$tag] = $current[$id];
        }

        return $versions;
    }

    protected function expiresAt(int|DateInterval|null $ttl): ?int
    {
        $ttl ??= $this->defaultTtl;

        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof DateInterval) {
            return (new DateTimeImmutable())->add($ttl)->getTimestamp();
        }

        return time() + $ttl;
    }

    protected function newVersion(): string
    {
        return bin2hex(random_bytes(8));
    }

    protected function validateKey(string $key, string $type = 'key'): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('A cache {type} cannot be empty.', 0, null, ['type' => $type]);
        }

        if (strpbrk($key, self::RESERVED_CHARACTERS) !== false) {
            throw new InvalidArgumentException('The cache {type} "{key}" contains a reserved character ({characters}).', 0, null, [
                'type' => $type,
                'key' => $key,
                'characters' => self::RESERVED_CHARACTERS,
            ]);
        }
    }
}