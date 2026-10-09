<?php

declare(strict_types=1);

namespace NeoPHP\Component\Routing;

use Closure;
use NeoPHP\Component\Routing\Route\Route;
use NeoPHP\Component\Routing\Route\RouteCollection;
use NeoPHP\Component\Routing\Route\RouteMatch;

interface RoutingManagerInterface
{
    public function match(string $method, string $path): RouteMatch;

    public function generate(string $name, array $parameters = [], bool $absolute = false): string;

    public function setBaseUrl(Closure|string|null $baseUrl): static;

    public function getBaseUrl(): string;

    public function setBasePath(Closure|string|null $basePath): static;

    public function getBasePath(): string;

    public function add(Route $route): static;

    public function loadYaml(string $file): static;

    public function getRoutes(): RouteCollection;
}