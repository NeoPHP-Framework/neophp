<?php

declare(strict_types=1);

namespace NeoPHP\Component\Config;

interface ConfigManagerInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function has(string $key): bool;

    public function set(string $key, mixed $value): void;

    public function all(): array;

    public function loadDirectory(string $directory, array $exclude = []): static;

    public function loadFile(string $file, string $key = '', bool $resolve = true): static;

    public function resolve(mixed $value): mixed;
}