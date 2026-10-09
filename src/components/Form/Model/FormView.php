<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Model;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use NeoPHP\Component\Form\Exception\FormException;
use Traversable;

class FormView implements ArrayAccess, IteratorAggregate, Countable
{
    public array $vars = [
        'value' => null,
        'attr' => [],
    ];

    public array $children = [];

    protected bool $rendered = false;

    public function __construct(public ?FormView $parent = null)
    {
    }

    public function isRendered(): bool
    {
        if ($this->rendered) {
            return true;
        }

        if ($this->children === []) {
            return false;
        }

        foreach ($this->children as $child) {
            if (!$child->isRendered()) {
                return false;
            }
        }

        return true;
    }

    public function setRendered(bool $rendered = true): static
    {
        $this->rendered = $rendered;

        return $this;
    }

    public function getRoot(): FormView
    {
        return $this->parent === null ? $this : $this->parent->getRoot();
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->children[$offset]);
    }

    public function offsetGet(mixed $offset): FormView
    {
        return $this->children[$offset] ?? throw new FormException('The field "{name}" does not exist in the form view "{form}".', 0, null, ['name' => $offset, 'form' => $this->vars['name'] ?? '']);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new FormException('A form view cannot be modified.');
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->children[$offset]);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->children);
    }

    public function count(): int
    {
        return count($this->children);
    }

    public function __get(string $name): mixed
    {
        return $this->children[$name] ?? $this->vars[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->children[$name]) || isset($this->vars[$name]);
    }
}