<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Pagination\Contract;

interface AdapterInterface
{
    public function count(): int;

    public function slice(int $offset, int $length): iterable;
}