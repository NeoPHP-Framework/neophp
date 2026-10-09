<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Collection;

use Closure;
use NeoPHP\Package\Orm\Contract\CollectionInterface;
use Traversable;

class PersistentCollection implements CollectionInterface
{
    protected ArrayCollection $collection;

    protected bool $initialized = false;

    protected array $snapshot = [];

    protected ?Closure $loader;

    public function __construct(protected object $owner, protected array $association, ?Closure $loader = null, array $elements = [])
    {
        $this->collection = new ArrayCollection($elements);
        $this->loader = $loader;

        if ($loader === null) {
            $this->initialized = true;
        }
    }

    public function getOwner(): object
    {
        return $this->owner;
    }

    public function getAssociation(): array
    {
        return $this->association;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;
        $loader = $this->loader;
        $this->loader = null;
        $this->collection = new ArrayCollection($loader !== null ? $loader($this) : []);
        $this->takeSnapshot();
    }

    public function hydrate(array $elements): void
    {
        $this->collection = new ArrayCollection($elements);
        $this->initialized = true;
        $this->loader = null;
        $this->takeSnapshot();
    }

    public function takeSnapshot(): void
    {
        $this->snapshot = $this->collection->getValues();
    }

    public function getSnapshot(): array
    {
        return $this->snapshot;
    }

    public function getInsertDiff(): array
    {
        if (!$this->initialized) {
            return [];
        }

        return array_values(array_filter($this->collection->getValues(), fn (mixed $element): bool => !in_array($element, $this->snapshot, true)));
    }

    public function getDeleteDiff(): array
    {
        if (!$this->initialized) {
            return [];
        }

        return array_values(array_filter($this->snapshot, fn (mixed $element): bool => !$this->collection->contains($element)));
    }

    public function isDirty(): bool
    {
        return $this->getInsertDiff() !== [] || $this->getDeleteDiff() !== [];
    }

    public function unwrap(): ArrayCollection
    {
        return $this->collection;
    }

    public function add(mixed $element): static
    {
        $this->initialize();
        $this->collection->add($element);

        return $this;
    }

    public function set(string|int $key, mixed $value): static
    {
        $this->initialize();
        $this->collection->set($key, $value);

        return $this;
    }

    public function get(string|int $key): mixed
    {
        $this->initialize();

        return $this->collection->get($key);
    }

    public function remove(string|int $key): mixed
    {
        $this->initialize();

        return $this->collection->remove($key);
    }

    public function removeElement(mixed $element): bool
    {
        $this->initialize();

        return $this->collection->removeElement($element);
    }

    public function contains(mixed $element): bool
    {
        $this->initialize();

        return $this->collection->contains($element);
    }

    public function containsKey(string|int $key): bool
    {
        $this->initialize();

        return $this->collection->containsKey($key);
    }

    public function indexOf(mixed $element): string|int|false
    {
        $this->initialize();

        return $this->collection->indexOf($element);
    }

    public function getKeys(): array
    {
        $this->initialize();

        return $this->collection->getKeys();
    }

    public function getValues(): array
    {
        $this->initialize();

        return $this->collection->getValues();
    }

    public function toArray(): array
    {
        $this->initialize();

        return $this->collection->toArray();
    }

    public function first(): mixed
    {
        $this->initialize();

        return $this->collection->first();
    }

    public function last(): mixed
    {
        $this->initialize();

        return $this->collection->last();
    }

    public function isEmpty(): bool
    {
        $this->initialize();

        return $this->collection->isEmpty();
    }

    public function clear(): static
    {
        $this->initialize();
        $this->collection->clear();

        return $this;
    }

    public function filter(callable $callback): CollectionInterface
    {
        $this->initialize();

        return $this->collection->filter($callback);
    }

    public function map(callable $callback): CollectionInterface
    {
        $this->initialize();

        return $this->collection->map($callback);
    }

    public function exists(callable $callback): bool
    {
        $this->initialize();

        return $this->collection->exists($callback);
    }

    public function slice(int $offset, ?int $length = null): array
    {
        $this->initialize();

        return $this->collection->slice($offset, $length);
    }

    public function count(): int
    {
        $this->initialize();

        return $this->collection->count();
    }

    public function getIterator(): Traversable
    {
        $this->initialize();

        return $this->collection->getIterator();
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