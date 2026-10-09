<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cache\Item;

use DateInterval;

class CacheItem
{
    public function __construct(protected string $key, protected int|DateInterval|null $ttl = null, protected array $tags = [])
    {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function expiresAfter(int|DateInterval|null $ttl): static
    {
        $this->ttl = $ttl;

        return $this;
    }

    public function getTtl(): int|DateInterval|null
    {
        return $this->ttl;
    }

    public function tag(string|array $tags): static
    {
        foreach ((array) $tags as $tag) {
            $this->tags[] = (string) $tag;
        }

        $this->tags = array_values(array_unique($this->tags));

        return $this;
    }

    public function getTags(): array
    {
        return $this->tags;
    }
}