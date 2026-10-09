<?php

declare(strict_types=1);

namespace NeoPHP\Component\Session;

interface SessionManagerInterface
{
    public function start(): void;

    public function isStarted(): bool;

    public function getId(): string;

    public function getName(): string;

    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): static;

    public function has(string $key): bool;

    public function remove(string $key): mixed;

    public function all(): array;

    public function clear(): static;

    public function regenerate(bool $destroy = true): static;

    public function invalidate(): static;

    public function save(): void;
}