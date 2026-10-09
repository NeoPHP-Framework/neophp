<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Violation;

use ArrayIterator;
use Countable;
use IteratorAggregate;

class ViolationList implements IteratorAggregate, Countable
{
    protected array $violations = [];

    public function add(Violation $violation): static
    {
        $this->violations[] = $violation;

        return $this;
    }

    public function addAll(self $list): static
    {
        foreach ($list as $violation) {
            $this->add($violation);
        }

        return $this;
    }

    public function all(): array
    {
        return $this->violations;
    }

    public function has(?string $path = null): bool
    {
        return $path === null ? $this->violations !== [] : $this->get($path) !== [];
    }

    public function get(string $path): array
    {
        return array_values(array_filter($this->violations, static fn (Violation $violation): bool => $violation->getPropertyPath() === $path));
    }

    public function messages(string $path): array
    {
        return array_map(static fn (Violation $violation): string => $violation->getMessage(), $this->get($path));
    }

    public function first(?string $path = null): ?string
    {
        $violations = $path === null ? $this->violations : $this->get($path);

        return isset($violations[0]) ? $violations[0]->getMessage() : null;
    }

    public function toArray(): array
    {
        $errors = [];

        foreach ($this->violations as $violation) {
            $errors[$violation->getPropertyPath()][] = $violation->getMessage();
        }

        return $errors;
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->violations);
    }

    public function count(): int
    {
        return count($this->violations);
    }

    public function __toString(): string
    {
        return implode("\n", array_map('strval', $this->violations));
    }
}