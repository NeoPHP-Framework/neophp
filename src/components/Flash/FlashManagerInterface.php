<?php

declare(strict_types=1);

namespace NeoPHP\Component\Flash;

interface FlashManagerInterface
{
    public function add(string $type, string $message): static;

    public function get(string $type): array;

    public function peek(string $type): array;

    public function all(): array;

    public function peekAll(): array;

    public function has(string $type): bool;

    public function clear(): static;
}