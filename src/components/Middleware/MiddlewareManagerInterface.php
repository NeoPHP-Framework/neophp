<?php

declare(strict_types=1);

namespace NeoPHP\Component\Middleware;

use NeoPHP\Component\Container\Exception\ContainerException;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Middleware\Exception\MiddlewareException;

interface MiddlewareManagerInterface
{
    /**
     * Runs the middlewares in their order, then the handler, and returns the response.
     *
     * @param Request $request The request
     * @param array<string> $middlewares Classes, aliases or groups of middlewares
     * @param callable(Request): Response $handler The handler called after the last middleware
     * @return Response The response
     * @throws MiddlewareException When a middleware is unknown, does not implement MiddlewareInterface or a group is circular
     * @throws ContainerException When a middleware cannot be built by the container
     */
    public function handle(Request $request, array $middlewares, callable $handler): Response;

    /**
     * Expands aliases and groups, recursively, into unique class names.
     *
     * @param array<string> $middlewares Classes, aliases or groups of middlewares
     * @return list<string> The middleware classes, in their order
     * @throws MiddlewareException When a middleware is unknown, does not implement MiddlewareInterface or a group is circular
     */
    public function resolve(array $middlewares): array;

    /**
     * Returns the classes of the global middlewares, run on every request.
     *
     * @return list<string> The middleware classes, in their order
     * @throws MiddlewareException When a middleware is unknown, does not implement MiddlewareInterface or a group is circular
     */
    public function getGlobal(): array;

    /**
     * Returns the classes of the route middlewares: the given ones, then the #[Middleware] attributes of the controller class and method, without the global ones.
     *
     * @param mixed $controller "Class::method", [class or object, method], an invokable class name or object
     * @param array<string> $middlewares Middlewares of the route
     * @return list<string> The middleware classes, in their order
     * @throws MiddlewareException When a middleware is unknown, does not implement MiddlewareInterface or a group is circular
     */
    public function forController(mixed $controller, array $middlewares = []): array;

    /**
     * Adds an alias of a middleware class.
     *
     * @param string $name The alias
     * @param string $class Class of the middleware
     * @return static The middleware manager
     */
    public function addAlias(string $name, string $class): static;

    /**
     * Adds a group of middlewares.
     *
     * @param string $name Name of the group
     * @param array<string> $middlewares Classes, aliases or other groups
     * @return static The middleware manager
     */
    public function addGroup(string $name, array $middlewares): static;

    /**
     * Adds a global middleware, run on every request; a middleware already global is ignored.
     *
     * @param string $middleware Class, alias or group
     * @return static The middleware manager
     */
    public function addGlobal(string $middleware): static;

    /**
     * Returns the aliases.
     *
     * @return array<string, string> The middleware class, by alias
     */
    public function getAliases(): array;

    /**
     * Returns the groups.
     *
     * @return array<string, list<string>> The middlewares, by group name
     */
    public function getGroups(): array;
}