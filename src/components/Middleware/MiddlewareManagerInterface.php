<?php

declare(strict_types=1);

namespace NeoPHP\Component\Middleware;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;

interface MiddlewareManagerInterface
{
    public function handle(Request $request, array $middlewares, callable $handler): Response;

    public function resolve(array $middlewares): array;

    public function getGlobal(): array;

    public function forController(mixed $controller, array $middlewares = []): array;

    public function addAlias(string $name, string $class): static;

    public function addGroup(string $name, array $middlewares): static;

    public function addGlobal(string $middleware): static;

    public function getAliases(): array;

    public function getGroups(): array;
}