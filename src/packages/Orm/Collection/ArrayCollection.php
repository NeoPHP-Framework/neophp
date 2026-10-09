<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Collection;

use ArrayIterator;
use NeoPHP\Package\Orm\Contract\CollectionInterface;
use Traversable;

class ArrayCollection implements CollectionInterface
{
    public function __construct(protected array $elements = [])
    {
    }

    public function add(mixed $element): static
    {
        $this->elements[] = $element;

        return $this;
    }

    public function set(string|int $key, mixed $value): static
    {
        $this->elements[$key] = $value;

        return $this;
    }

    public function get(string|int $key): mixed
    {
        return $this->elements[$key] ?? null;
    }

    public function remove(string|int $key): mixed
    {
        if (!array_key_exists($key, $this->elements)) {
            return null;
        }

        $removed = $this->elements[$key];
        unset($this->elements[$key]);

        return $removed;
    }

    public function removeElement(mixed $element): bool
    {
        $key = $this->indexOf($element);

        if ($key === false) {
            return false;
        }

        unset($this->elements[$key]);

        return true;
    }

    public function contains(mixed $element): bool
    {
        return in_array($element, $this->elements, true);
    }

    public function containsKey(string|int $key): bool
    {
        return array_key_exists($key, $this->elements);
    }

    public function indexOf(mixed $element): string|int|false
    {
        return array_search($element, $this->elements, true);
    }

    public function getKeys(): array
    {
        return array_keys($this->elements);
    }

    public function getValues(): array
    {
        return array_values($this->elements);
    }

    public function toArray(): array
    {
        return $this->elements;
    }

    public function first(): mixed
    {
        return $this->elements === [] ? null : $this->elements[array_key_first($this->elements)];
    }

    public function last(): mixed
    {
        return $this->elements === [] ? null : $this->elements[array_key_last($this->elements)];
    }

    public function isEmpty(): bool
    {
        return $this->elements === [];
    }

    public function clear(): static
    {
        $this->elements = [];

        return $this;
    }

    public function filter(callable $callback): CollectionInterface
    {
        return new ArrayCollection(array_filter($this->elements, $callback, ARRAY_FILTER_USE_BOTH));
    }

    public function map(callable $callback): CollectionInterface
    {
        return new ArrayCollection(array_map($callback, $this->elements));
    }

    public function exists(callable $callback): bool
    {
        foreach ($this->elements as $key => $element) {
            if ($callback($element, $key)) {
                return true;
            }
        }

        return false;
    }

    public function slice(int $offset, ?int $length = null): array
    {
        return array_slice($this->elements, $offset, $length, true);
    }

    public function count(): int
    {
        return count($this->elements);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->elements);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->containsKey($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $offset === null ? $this->add($value) : $this->set($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->remove($offset);
    }
}