<?php

declare(strict_types=1);

namespace NeoPHP\Component\Middleware;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Kernel\Attribute\Component;
use NeoPHP\Component\Middleware\Attribute\Middleware;
use NeoPHP\Component\Middleware\Contract\MiddlewareInterface;
use NeoPHP\Component\Middleware\Exception\MiddlewareException;
use NeoPHP\Component\Middleware\Pipeline\Pipeline;
use NeoPHP\Component\Middleware\Provider\MiddlewareProvider;
use ReflectionAttribute;
use ReflectionClass;

#[Component(provider: MiddlewareProvider::class)]
final class MiddlewareManager implements MiddlewareManagerInterface
{
    protected ?ContainerManagerInterface $container = null;

    protected array $global = [];

    protected array $aliases = [];

    protected array $groups = [];

    public function __construct(?ContainerManagerInterface $container = null, array $global = [], array $aliases = [], array $groups = [])
    {
        $this->container = $container;

        foreach ($aliases as $name => $class) {
            $this->addAlias((string) $name, (string) $class);
        }

        foreach ($groups as $name => $middlewares) {
            $this->addGroup((string) $name, (array) $middlewares);
        }

        foreach ($global as $middleware) {
            $this->addGlobal((string) $middleware);
        }
    }

    public function handle(Request $request, array $middlewares, callable $handler): Response
    {
        return (new Pipeline($this->resolve($middlewares), $handler, fn (string $class): object => $this->instantiate($class)))->handle($request);
    }

    public function resolve(array $middlewares): array
    {
        $classes = [];

        foreach ($middlewares as $middleware) {
            foreach ($this->expand((string) $middleware, []) as $class) {
                $classes[$class] = $class;
            }
        }

        return array_values($classes);
    }

    public function getGlobal(): array
    {
        return $this->resolve($this->global);
    }

    public function forController(mixed $controller, array $middlewares = []): array
    {
        $middlewares = [...$middlewares, ...$this->controllerMiddlewares($controller)];

        return array_values(array_diff($this->resolve($middlewares), $this->getGlobal()));
    }

    public function addAlias(string $name, string $class): static
    {
        $this->aliases[$name] = $class;

        return $this;
    }

    public function addGroup(string $name, array $middlewares): static
    {
        $this->groups[$name] = array_values(array_map('strval', $middlewares));

        return $this;
    }

    public function addGlobal(string $middleware): static
    {
        if (!in_array($middleware, $this->global, true)) {
            $this->global[] = $middleware;
        }

        return $this;
    }

    public function getAliases(): array
    {
        return $this->aliases;
    }

    public function getGroups(): array
    {
        return $this->groups;
    }

    protected function expand(string $middleware, array $resolving): array
    {
        if (isset($resolving[$middleware])) {
            throw new MiddlewareException('Circular reference detected in the middleware group "{group}".', 0, null, ['group' => $middleware]);
        }

        if (isset($this->groups[$middleware])) {
            $resolving[$middleware] = true;
            $classes = [];

            foreach ($this->groups[$middleware] as $item) {
                array_push($classes, ...$this->expand($item, $resolving));
            }

            return $classes;
        }

        $class = $this->aliases[$middleware] ?? $middleware;

        if (!class_exists($class)) {
            throw new MiddlewareException('Unknown middleware "{middleware}": it is neither an alias, a group nor a class.', 0, null, ['middleware' => $middleware]);
        }

        if (!is_subclass_of($class, MiddlewareInterface::class)) {
            throw new MiddlewareException('The middleware "{middleware}" must implement {interface}.', 0, null, [
                'middleware' => $class,
                'interface' => MiddlewareInterface::class,
            ]);
        }

        return [$class];
    }

    protected function controllerMiddlewares(mixed $controller): array
    {
        [$class, $method] = $this->controllerTarget($controller);

        if ($class === null || !class_exists($class)) {
            return [];
        }

        $reflection = new ReflectionClass($class);
        $middlewares = $this->attributeMiddlewares($reflection->getAttributes(Middleware::class, ReflectionAttribute::IS_INSTANCEOF));

        if ($method !== null && $reflection->hasMethod($method)) {
            array_push($middlewares, ...$this->attributeMiddlewares($reflection->getMethod($method)->getAttributes(Middleware::class, ReflectionAttribute::IS_INSTANCEOF)));
        }

        return $middlewares;
    }

    protected function controllerTarget(mixed $controller): array
    {
        if (is_string($controller) && str_contains($controller, '::')) {
            return explode('::', $controller, 2);
        }

        if (is_string($controller)) {
            return [$controller, '__invoke'];
        }

        if (is_array($controller) && count($controller) === 2) {
            return [is_object($controller[0]) ? $controller[0]::class : (string) $controller[0], (string) $controller[1]];
        }

        if (is_object($controller) && !$controller instanceof \Closure) {
            return [$controller::class, '__invoke'];
        }

        return [null, null];
    }

    protected function attributeMiddlewares(array $attributes): array
    {
        $middlewares = [];

        foreach ($attributes as $attribute) {
            array_push($middlewares, ...$attribute->newInstance()->middlewares);
        }

        return $middlewares;
    }

    protected function instantiate(string $class): object
    {
        return $this->container !== null ? $this->container->get($class) : new $class();
    }
}