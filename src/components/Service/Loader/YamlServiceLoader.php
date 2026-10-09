<?php

declare(strict_types=1);

namespace NeoPHP\Component\Service\Loader;

use NeoPHP\Component\Container\Attribute\Autowire;
use NeoPHP\Component\Kernel\Discovery\ClassFinder;
use NeoPHP\Component\Service\Exception\ServiceException;
use NeoPHP\Package\Yaml\YamlManagerInterface;
use ReflectionClass;

class YamlServiceLoader
{
    public const SERVICE_KEYS = ['class', 'arguments', 'calls', 'factory', 'shared', 'alias'];

    public const RESOURCE_KEYS = ['resource', 'exclude', 'shared'];

    public const DEFAULTS_KEYS = ['shared', 'autowire'];

    protected array $resources = [];

    public function __construct(protected YamlManagerInterface $yaml)
    {
    }

    public function load(string $file): array
    {
        $real = realpath($file);

        if ($real === false) {
            throw new ServiceException('The services file "{file}" does not exist.', 0, null, ['file' => $file]);
        }

        $this->resources[$real] = (int) filemtime($real);
        $content = $this->yaml->parseFile($real) ?? [];

        if (!is_array($content)) {
            throw new ServiceException('The services file "{file}" must contain a mapping.', 0, null, ['file' => $real]);
        }

        $unknown = array_diff(array_keys($content), ['services']);

        if ($unknown !== []) {
            throw new ServiceException('Unknown root key(s) "{keys}" in "{file}": only "services" is allowed.', 0, null, ['keys' => implode('", "', $unknown), 'file' => $real]);
        }

        $entries = (array) ($content['services'] ?? []);
        $defaults = ['shared' => true];
        $services = [];
        $aliases = [];

        if (isset($entries['_defaults'])) {
            $this->assertKeys('_defaults', (array) $entries['_defaults'], self::DEFAULTS_KEYS, $real);
            $defaults = array_replace($defaults, (array) $entries['_defaults']);
            unset($entries['_defaults']);
        }

        foreach ($entries as $id => $entry) {
            $id = (string) $id;

            if (is_string($entry) && str_starts_with($entry, '@')) {
                $aliases[$id] = substr($entry, 1);
                continue;
            }

            if (str_ends_with($id, '\\')) {
                foreach ($this->resource($id, (array) $entry, (bool) $defaults['shared'], $real) as $class => $definition) {
                    $services[$class] = $definition;
                }

                continue;
            }

            $entry = $entry === null ? [] : $entry;

            if (!is_array($entry)) {
                throw new ServiceException('The definition of the service "{id}" in "{file}" must be a mapping, an alias ("@id") or null.', 0, null, ['id' => $id, 'file' => $real]);
            }

            $this->assertKeys($id, $entry, self::SERVICE_KEYS, $real);

            if (isset($entry['alias'])) {
                $aliases[$id] = ltrim((string) $entry['alias'], '@');
                continue;
            }

            $services[$id] = $this->definition($id, $entry, (bool) $defaults['shared'], $real);
            unset($aliases[$id]);
        }

        return [
            'services' => $services,
            'aliases' => $aliases,
            'interfaces' => $this->interfaces($services),
        ];
    }

    public function getResources(): array
    {
        return $this->resources;
    }

    protected function definition(string $id, array $entry, bool $shared, string $file): array
    {
        $class = (string) ($entry['class'] ?? $id);
        $factory = $entry['factory'] ?? null;

        if ($factory === null && !class_exists($class)) {
            throw new ServiceException('The class "{class}" of the service "{id}" in "{file}" does not exist.', 0, null, ['class' => $class, 'id' => $id, 'file' => $file]);
        }

        $calls = [];

        foreach ((array) ($entry['calls'] ?? []) as $call) {
            $call = (array) $call;

            if (!isset($call[0]) || !is_string($call[0])) {
                throw new ServiceException('Each call of the service "{id}" must be [method, [arguments]].', 0, null, ['id' => $id]);
            }

            $calls[] = [$call[0], (array) ($call[1] ?? [])];
        }

        return [
            'class' => $class,
            'arguments' => $this->arguments((array) ($entry['arguments'] ?? [])),
            'calls' => $calls,
            'factory' => $factory,
            'shared' => (bool) ($entry['shared'] ?? $this->classShared($class) ?? $shared),
            'source' => 'definition',
        ];
    }

    protected function resource(string $namespace, array $entry, bool $shared, string $file): array
    {
        $this->assertKeys($namespace, $entry, self::RESOURCE_KEYS, $file);

        if (!isset($entry['resource'])) {
            throw new ServiceException('The import "{namespace}" in "{file}" must define a "resource".', 0, null, ['namespace' => $namespace, 'file' => $file]);
        }

        $path = $this->path((string) $entry['resource'], $file);
        $excluded = array_map(fn (mixed $exclude): string => $this->path((string) $exclude, $file), (array) ($entry['exclude'] ?? []));
        $finder = new ClassFinder();
        $services = [];

        foreach ($finder->map($path) as $class => $classFile) {
            if (!str_starts_with($class, $namespace) || $this->isExcluded(str_replace('\\', '/', $classFile), $excluded) || !class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if (!$reflection->isInstantiable()) {
                continue;
            }

            $services[$class] = [
                'class' => $class,
                'arguments' => [],
                'calls' => [],
                'factory' => null,
                'shared' => (bool) ($entry['shared'] ?? $this->classShared($class) ?? $shared),
                'source' => 'resource',
            ];
        }

        $this->resources += $finder->getResources();

        return $services;
    }

    protected function interfaces(array $services): array
    {
        $interfaces = [];

        foreach ($services as $id => $definition) {
            if ($definition['factory'] !== null || !class_exists($definition['class'])) {
                continue;
            }

            foreach ((new ReflectionClass($definition['class']))->getInterfaces() as $interface) {
                if (!$interface->isInternal()) {
                    $interfaces[$interface->getName()][] = (string) $id;
                }
            }
        }

        return $interfaces;
    }

    protected function arguments(array $arguments): array
    {
        $normalized = [];

        foreach ($arguments as $name => $value) {
            $normalized[is_string($name) ? ltrim($name, '$') : $name] = $value;
        }

        return $normalized;
    }

    protected function classShared(string $class): ?bool
    {
        if (!class_exists($class)) {
            return null;
        }

        $attributes = (new ReflectionClass($class))->getAttributes(Autowire::class);

        return $attributes === [] ? null : $attributes[0]->newInstance()->shared;
    }

    protected function path(string $path, string $file): string
    {
        $path = preg_match('#^([a-zA-Z]:)?[/\\\\]#', $path) === 1 ? $path : dirname($file) . '/' . $path;
        $real = realpath(rtrim($path, '/\\'));

        return str_replace('\\', '/', $real !== false ? $real : $path);
    }

    protected function isExcluded(string $file, array $excluded): bool
    {
        foreach ($excluded as $pattern) {
            if ($file === $pattern || str_starts_with($file, rtrim($pattern, '/') . '/') || fnmatch($pattern, $file)) {
                return true;
            }
        }

        return false;
    }

    protected function assertKeys(string $id, array $entry, array $allowed, string $file): void
    {
        $unknown = array_diff(array_keys($entry), $allowed);

        if ($unknown !== []) {
            throw new ServiceException('Unknown key(s) "{keys}" for "{id}" in "{file}". Allowed keys: "{allowed}".', 0, null, [
                'keys' => implode('", "', $unknown),
                'id' => $id,
                'file' => $file,
                'allowed' => implode('", "', $allowed),
            ]);
        }
    }
}