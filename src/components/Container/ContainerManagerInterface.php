<?php

declare(strict_types=1);

namespace NeoPHP\Component\Container;

interface ContainerManagerInterface
{
    public function get(string $id): mixed;

    public function has(string $id): bool;

    public function bind(string $id, mixed $concrete = null, bool $shared = false): static;

    public function singleton(string $id, mixed $concrete = null): static;

    public function instance(string $id, mixed $value): static;

    public function alias(string $alias, string $id): static;

    public function bound(string $id): bool;

    public function resolved(string $id): bool;

    public function make(string $id, array $parameters = []): mixed;

    public function instantiate(string $class, array $parameters = []): object;

    public function inject(object $object): object;

    public function call(callable|array|string $callable, array $parameters = []): mixed;

    public function getDefinitions(): array;

    public function getAliases(): array;
}