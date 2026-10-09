<?php

declare(strict_types=1);

namespace NeoPHP\Component\Controller;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;

interface ControllerManagerInterface
{
    public function resolve(mixed $controller): callable;

    public function resolveArguments(callable $controller, Request $request, array $routeParameters = []): array;

    public function dispatch(mixed $controller, Request $request, array $routeParameters = []): Response;
}