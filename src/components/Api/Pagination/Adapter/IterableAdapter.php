<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Pagination\Adapter;

use Countable;
use NeoPHP\Component\Api\Pagination\Contract\AdapterInterface;

class IterableAdapter implements AdapterInterface
{
    protected ?array $items = null;

    public function __construct(protected iterable $iterable)
    {
    }

    public function count(): int
    {
        if ($this->items === null && $this->iterable instanceof Countable) {
            return count($this->iterable);
        }

        return count($this->items());
    }

    public function slice(int $offset, int $length): iterable
    {
        return array_slice($this->items(), $offset, $length);
    }

    protected function items(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        $items = [];

        foreach ($this->iterable as $item) {
            $items[] = $item;
        }

        return $this->items = $items;
    }
}