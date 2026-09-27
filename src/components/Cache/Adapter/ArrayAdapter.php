<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cache\Adapter;

use NeoPHP\Component\Cache\Contract\AbstractAdapter;

class ArrayAdapter extends AbstractAdapter
{
    protected array $items = [];

    public function __construct(string $namespace = '')
    {
        $this->namespace = $namespace;
    }

    public function getName(): string
    {
        return 'array';
    }

    public function fetch(string $id): ?string
    {
        if (!isset($this->items[$id])) {
            return null;
        }

        [$data, $expiresAt] = $this->items[$id];

        if ($this->isExpired($expiresAt)) {
            unset($this->items[$id]);

            return null;
        }

        return $data;
    }

    public function save(string $id, string $data, ?int $expiresAt): bool
    {
        $this->items[$id] = [$data, $expiresAt];

        return true;
    }

    public function remove(string $id): bool
    {
        unset($this->items[$id]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    public function prune(): int
    {
        $pruned = 0;

        foreach ($this->items as $id => [, $expiresAt]) {
            if ($this->isExpired($expiresAt)) {
                unset($this->items[$id]);
                $pruned++;
            }
        }

        return $pruned;
    }
}