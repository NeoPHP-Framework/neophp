<?php

declare(strict_types=1);

namespace NeoPHP\Component\View\Engine;

interface EngineInterface
{
    public function getName(): string;

    public function getExtensions(): array;

    public function render(string $template, string $file, array $parameters): string;

    public function addFunction(string $name, callable $function, bool $safe = false): void;

    public function addFilter(string $name, callable $filter, bool $safe = false): void;

    public function addGlobal(string $name, mixed $value): void;

    public function addPath(string $path, ?string $namespace = null): void;
}