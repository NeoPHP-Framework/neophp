<?php

declare(strict_types=1);

namespace NeoPHP\Component\Routing\Loader;

use NeoPHP\Component\Kernel\Discovery\ClassFinder;
use NeoPHP\Component\Routing\Attribute\Route as RouteAttribute;
use NeoPHP\Component\Routing\Exception\RoutingException;
use NeoPHP\Component\Routing\Route\Route;
use NeoPHP\Component\Routing\Route\RouteCollection;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

class AttributeRouteLoader
{
    protected array $resources = [];

    public function load(string $path): RouteCollection
    {
        $real = realpath($path);

        if ($real === false) {
            throw new RoutingException(sprintf('The controllers resource "%s" does not exist.', $path));
        }

        $collection = new RouteCollection();
        $finder = new ClassFinder();

        foreach ($finder->find($real, '#[') as $class) {
            foreach ($this->loadClass($class) as $route) {
                $this->append($collection, $route);
            }
        }

        $this->resources += $finder->getResources();

        return $collection;
    }

    public function loadClass(string $class): RouteCollection
    {
        $collection = new RouteCollection();

        if (!class_exists($class)) {
            return $collection;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait() || $reflection->isEnum()) {
            return $collection;
        }

        $globals = $this->attributes($reflection->getAttributes(RouteAttribute::class, ReflectionAttribute::IS_INSTANCEOF));
        $hasMethodRoutes = false;

        foreach ($reflection->getMethods() as $method) {
            $attributes = $this->attributes($method->getAttributes(RouteAttribute::class, ReflectionAttribute::IS_INSTANCEOF));

            if ($attributes === []) {
                continue;
            }

            if (!$method->isPublic() || $method->isStatic() || $method->isConstructor()) {
                throw new RoutingException(sprintf('The route attribute of "%s::%s()" must be on a public, non-static method.', $class, $method->getName()));
            }

            $hasMethodRoutes = true;

            foreach ($globals === [] ? [null] : $globals as $global) {
                foreach ($attributes as $attribute) {
                    $this->append($collection, $this->createRoute($reflection, $method, $attribute, $global));
                }
            }
        }

        if (!$hasMethodRoutes && $globals !== [] && $reflection->hasMethod('__invoke')) {
            $invoke = $reflection->getMethod('__invoke');

            foreach ($globals as $attribute) {
                $this->append($collection, $this->createRoute($reflection, $invoke, $attribute, null));
            }
        }

        return $collection;
    }

    public function getResources(): array
    {
        return $this->resources;
    }

    protected function createRoute(ReflectionClass $class, ReflectionMethod $method, RouteAttribute $attribute, ?RouteAttribute $global): Route
    {
        $name = $attribute->name ?? $this->defaultName($class->getName(), $method->getName(), $global?->name !== null);
        $name = rtrim(($global->name ?? '') . $name, '_');
        $controller = $method->getName() === '__invoke' ? $class->getName() : $class->getName() . '::' . $method->getName();

        $route = new Route(
            $name,
            ($global->path ?? '') . '/' . ltrim($attribute->path, '/'),
            $controller,
            $attribute->getMethods() !== [] ? $attribute->getMethods() : ($global?->getMethods() ?? []),
            array_replace($global->requirements ?? [], $attribute->requirements),
            array_replace($global->defaults ?? [], $attribute->defaults),
            $this->options($global, $attribute),
        );

        return $route->setSource($class->getName() . '::' . $method->getName() . '()');
    }

    protected function options(?RouteAttribute $global, RouteAttribute $attribute): array
    {
        $options = array_replace($global->options ?? [], $attribute->options);
        $middlewares = [...($global->middlewares ?? []), ...$attribute->middlewares];

        if ($middlewares !== []) {
            $options['middlewares'] = array_values(array_unique([...(array) ($options['middlewares'] ?? []), ...$middlewares]));
        }

        return $options;
    }

    protected function defaultName(string $class, string $method, bool $prefixed = false): string
    {
        $parts = [];

        foreach ($prefixed ? [] : explode('\\', $class) as $segment) {
            $segment = (string) preg_replace('/Controller$/', '', $segment);

            if ($segment !== '') {
                $parts[] = $segment;
            }
        }

        if ($method !== '__invoke') {
            $parts[] = $method;
        }

        return strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', implode('_', $parts)));
    }

    protected function attributes(array $attributes): array
    {
        return array_map(static fn (ReflectionAttribute $attribute): RouteAttribute => $attribute->newInstance(), $attributes);
    }

    protected function append(RouteCollection $collection, Route $route): void
    {
        $existing = $collection->get($route->getName());

        if ($existing !== null) {
            throw new RoutingException(sprintf('The route "%s" is defined twice: in "%s" and in "%s".', $route->getName(), $existing->getSource(), $route->getSource()));
        }

        $collection->add($route);
    }
}