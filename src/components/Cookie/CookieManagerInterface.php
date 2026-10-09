<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cookie;

use NeoPHP\Component\Http\Response\Response;

interface CookieManagerInterface
{
    public function get(string $name, mixed $default = null): mixed;

    public function getSigned(string $name, mixed $default = null): mixed;

    public function has(string $name): bool;

    public function all(): array;

    public function set(string $name, string $value, array $options = []): static;

    public function remove(string $name, array $options = []): static;

    public function getQueued(): array;

    public function apply(Response $response): Response;
}