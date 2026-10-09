<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Contract;

use ArrayAccess;
use Countable;
use IteratorAggregate;

interface CollectionInterface extends Countable, IteratorAggregate, ArrayAccess
{
    public function add(mixed $element): static;

    public function set(string|int $key, mixed $value): static;

    public function get(string|int $key): mixed;

    public function remove(string|int $key): mixed;

    public function removeElement(mixed $element): bool;

    public function contains(mixed $element): bool;

    public function containsKey(string|int $key): bool;

    public function indexOf(mixed $element): string|int|false;

    public function getKeys(): array;

    public function getValues(): array;

    public function toArray(): array;

    public function first(): mixed;

    public function last(): mixed;

    public function isEmpty(): bool;

    public function clear(): static;

    public function filter(callable $callback): CollectionInterface;

    public function map(callable $callback): CollectionInterface;

    public function exists(callable $callback): bool;

    public function slice(int $offset, ?int $length = null): array;
}