<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Pagination\Adapter;

use NeoPHP\Component\Api\Pagination\Contract\AdapterInterface;

class ArrayAdapter implements AdapterInterface
{
    public function __construct(protected array $items)
    {
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function slice(int $offset, int $length): iterable
    {
        return array_slice(array_values($this->items), $offset, $length);
    }
}