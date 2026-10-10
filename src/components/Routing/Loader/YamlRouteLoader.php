<?php

declare(strict_types=1);

namespace NeoPHP\Component\Routing\Loader;

use NeoPHP\Component\Routing\Exception\RoutingException;
use NeoPHP\Component\Routing\Route\Route;
use NeoPHP\Component\Routing\Route\RouteCollection;
use NeoPHP\Package\Yaml\YamlManagerInterface;

class YamlRouteLoader
{
    private const ROUTE_KEYS = ['path', 'controller', 'methods', 'requirements', 'defaults', 'options', 'middlewares'];
    private const IMPORT_KEYS = ['resource', 'type', 'prefix', 'name_prefix', 'requirements', 'defaults', 'options', 'methods', 'middlewares'];
    private const TYPES = ['yaml', 'attribute'];

    private array $loading = [];

    protected array $resources = [];

    protected mixed $resolver;

    protected mixed $packages;

    public function __construct(protected YamlManagerInterface $yaml, ?callable $resolver = null, ?callable $packages = null)
    {
        $this->resolver = $resolver;
        $this->packages = $packages;
    }

    public function load(string $file): RouteCollection
    {
        $real = realpath($file);

        if ($real === false) {
            throw new RoutingException(sprintf('The routes file "%s" does not exist.', $file));
        }

        if (isset($this->loading[$real])) {
            throw new RoutingException(sprintf('Circular import detected for routes file "%s".', $real));
        }

        $this->loading[$real] = true;
        $this->resources[$real] = (int) filemtime($real);

        try {
            $definitions = $this->yaml->parseFile($real) ?? [];

            if ($this->resolver !== null && is_array($definitions)) {
                $definitions = ($this->resolver)($definitions);
            }

            if (!is_array($definitions)) {
                throw new RoutingException(sprintf('The routes file "%s" must contain a mapping of routes.', $real));
            }

            $collection = new RouteCollection();

            foreach ($definitions as $name => $definition) {
                $name = (string) $name;

                if (!is_array($definition)) {
                    throw new RoutingException(sprintf('The definition of route "%s" in "%s" must be a mapping.', $name, $real));
                }

                if (isset($definition['resource'])) {
                    foreach ($this->import($name, $definition, $real) as $route) {
                        $this->append($collection, $route);
                    }
                } else {
                    $this->append($collection, $this->createRoute($name, $definition, $real));
                }
            }

            return $collection;
        } finally {
            unset($this->loading[$real]);
        }
    }

    public function getResources(): array
    {
        return $this->resources;
    }

    private function append(RouteCollection $collection, Route $route): void
    {
        $existing = $collection->get($route->getName());

        if ($existing !== null) {
            throw new RoutingException(sprintf('The route "%s" is defined twice: in "%s" and in "%s".', $route->getName(), $existing->getSource(), $route->getSource()));
        }

        $collection->add($route);
    }

    private function createRoute(string $name, array $definition, string $file): Route
    {
        $this->assertKeys($name, $definition, self::ROUTE_KEYS, $file);

        if (!isset($definition['path']) || !is_string($definition['path'])) {
            throw new RoutingException(sprintf('The route "%s" in "%s" must define a "path".', $name, $file));
        }

        if (!isset($definition['controller'])) {
            throw new RoutingException(sprintf('The route "%s" in "%s" must define a "controller".', $name, $file));
        }

        $route = new Route(
            $name,
            $definition['path'],
            $definition['controller'],
            $this->methods($definition['methods'] ?? []),
            $this->map($definition['requirements'] ?? [], 'requirements', $name, $file),
            $this->map($definition['defaults'] ?? [], 'defaults', $name, $file),
            $this->withMiddlewares($this->map($definition['options'] ?? [], 'options', $name, $file), $this->middlewares($definition['middlewares'] ?? [])),
        );

        return $route->setSource($file . ' (' . $name . ')');
    }

    private function import(string $name, array $definition, string $file): RouteCollection
    {
        $this->assertKeys($name, $definition, self::IMPORT_KEYS, $file);

        $resource = (string) $definition['resource'];

        if (str_starts_with($resource, '@')) {
            $path = $this->packageResource(substr($resource, 1), $name, $file);

            if ($path === null) {
                return new RouteCollection();
            }
        } else {
            $path = preg_match('#^([a-zA-Z]:)?[/\\\\]#', $resource) === 1 ? $resource : dirname($file) . DIRECTORY_SEPARATOR . $resource;
        }

        $imported = $this->loadResource($name, $path, $definition['type'] ?? null, $file);

        $prefix = trim((string) ($definition['prefix'] ?? ''), '/');
        $namePrefix = (string) ($definition['name_prefix'] ?? '');
        $requirements = $this->map($definition['requirements'] ?? [], 'requirements', $name, $file);
        $defaults = $this->map($definition['defaults'] ?? [], 'defaults', $name, $file);
        $options = $this->map($definition['options'] ?? [], 'options', $name, $file);
        $methods = $this->methods($definition['methods'] ?? []);
        $middlewares = $this->middlewares($definition['middlewares'] ?? []);

        $collection = new RouteCollection();

        foreach ($imported as $route) {
            $collection->add((new Route(
                $namePrefix . $route->getName(),
                ($prefix !== '' ? '/' . $prefix : '') . $route->getPath(),
                $route->getController(),
                $route->getMethods() !== [] ? $route->getMethods() : $methods,
                $route->getRequirements() + $requirements,
                $route->getDefaults() + $defaults,
                $this->withMiddlewares($route->getOptions() + $options, $middlewares, true),
            ))->setSource($route->getSource()));
        }

        return $collection;
    }

    private function packageResource(string $package, string $name, string $file): ?string
    {
        $resource = $this->packages !== null ? ($this->packages)($package) : null;

        if ($resource === false) {
            return null;
        }

        if (!is_string($resource) || $resource === '') {
            throw new RoutingException(sprintf('The import "%s" in "%s" refers to "@%s", which is not an installed NeoPHP package with routes.', $name, $file, $package));
        }

        return $resource;
    }

    private function loadResource(string $name, string $path, mixed $type, string $file): RouteCollection
    {
        $type ??= is_dir($path) || str_ends_with(strtolower($path), '.php') ? 'attribute' : 'yaml';

        if (!in_array($type, self::TYPES, true)) {
            throw new RoutingException(sprintf('Unknown type "%s" for the import "%s" in "%s". Allowed types: "%s".', (string) $type, $name, $file, implode('", "', self::TYPES)));
        }

        if ($type === 'yaml') {
            return $this->load($path);
        }

        $loader = new AttributeRouteLoader();
        $routes = $loader->load($path);
        $this->resources += $loader->getResources();

        return $routes;
    }

    private function middlewares(mixed $middlewares): array
    {
        if (is_string($middlewares)) {
            $middlewares = preg_split('/\s*[|,]\s*/', trim($middlewares), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return array_values(array_map('strval', (array) $middlewares));
    }

    private function withMiddlewares(array $options, array $middlewares, bool $prepend = false): array
    {
        if ($middlewares === []) {
            return $options;
        }

        $current = (array) ($options['middlewares'] ?? []);
        $options['middlewares'] = array_values(array_unique($prepend ? [...$middlewares, ...$current] : [...$current, ...$middlewares]));

        return $options;
    }

    private function methods(mixed $methods): array
    {
        if (is_string($methods)) {
            $methods = preg_split('/\s*[|,]\s*/', trim($methods), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return array_values(array_map(static fn (mixed $m): string => strtoupper((string) $m), (array) $methods));
    }

    private function map(mixed $value, string $key, string $name, string $file): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            throw new RoutingException(sprintf('The "%s" of route "%s" in "%s" must be a mapping.', $key, $name, $file));
        }

        return $value;
    }

    private function assertKeys(string $name, array $definition, array $allowed, string $file): void
    {
        $unknown = array_diff(array_keys($definition), $allowed);

        if ($unknown !== []) {
            throw new RoutingException(sprintf(
                'Unknown key(s) "%s" for route "%s" in "%s". Allowed keys: "%s".',
                implode('", "', $unknown),
                $name,
                $file,
                implode('", "', $allowed),
            ));
        }
    }
}