<?php

declare(strict_types=1);

namespace NeoPHP\Component\Service;

use Closure;
use NeoPHP\Component\Config\ConfigManager;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Kernel\Attribute\Component;
use NeoPHP\Component\Service\Exception\ServiceException;
use NeoPHP\Component\Service\Provider\ServiceProvider;
use NeoPHP\Package\Yaml\YamlManager;

#[Component(provider: ServiceProvider::class, requires: [ConfigManager::class, YamlManager::class])]
final class ServiceManager implements ServiceManagerInterface
{
    protected ContainerManagerInterface $container;

    protected ?Closure $resolver = null;

    protected array $services = [];

    protected array $aliases = [];

    protected array $interfaces = [];

    public function __construct(ContainerManagerInterface $container, ?callable $resolver = null)
    {
        $this->container = $container;
        $this->resolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    public function register(array $definitions): static
    {
        foreach ((array) ($definitions['services'] ?? []) as $id => $definition) {
            $this->services[(string) $id] = $definition;
            $this->bind((string) $id, $definition);
        }

        foreach ((array) ($definitions['aliases'] ?? []) as $alias => $target) {
            $this->aliases[(string) $alias] = (string) $target;
            $this->container->alias((string) $alias, (string) $target);
        }

        foreach ((array) ($definitions['interfaces'] ?? []) as $interface => $ids) {
            $this->bindInterface((string) $interface, array_values(array_unique((array) $ids)));
        }

        return $this;
    }

    public function getServices(): array
    {
        return $this->services;
    }

    public function getAliases(): array
    {
        return $this->aliases;
    }

    public function getInterfaces(): array
    {
        return $this->interfaces;
    }

    protected function bind(string $id, array $definition): void
    {
        if ($definition['arguments'] === [] && $definition['calls'] === [] && $definition['factory'] === null && $definition['class'] === $id) {
            $this->container->bind($id, $id, (bool) $definition['shared']);

            return;
        }

        $this->container->bind($id, fn (ContainerManagerInterface $container): mixed => $this->build($id, $definition), (bool) $definition['shared']);
    }

    protected function bindInterface(string $interface, array $ids): void
    {
        if ($this->container->bound($interface) || isset($this->aliases[$interface]) || isset($this->services[$interface])) {
            return;
        }

        if (count($ids) === 1) {
            $this->interfaces[$interface] = $ids[0];
            $this->container->alias($interface, $ids[0]);

            return;
        }

        $this->interfaces[$interface] = $ids;
        $this->container->bind($interface, static function () use ($interface, $ids): never {
            throw new ServiceException('Several services implement "{interface}" ({services}): define which one to use with an alias in config/services.yaml ({interface}: \'@App\\Service\\...\').', 0, null, [
                'interface' => $interface,
                'services' => implode(', ', $ids),
            ]);
        });
    }

    protected function build(string $id, array $definition): object
    {
        $arguments = $this->resolveValue($definition['arguments']);

        if ($definition['factory'] !== null) {
            $object = $this->container->call($this->factory($definition['factory'], $id), $arguments);

            if (!is_object($object)) {
                throw new ServiceException('The factory of the service "{id}" must return an object ({type} returned).', 0, null, ['id' => $id, 'type' => get_debug_type($object)]);
            }

            $this->container->inject($object);
        } else {
            $object = $this->container->instantiate($definition['class'], $arguments);
        }

        foreach ($definition['calls'] as [$method, $callArguments]) {
            if (!method_exists($object, $method)) {
                throw new ServiceException('The method "{method}" called on the service "{id}" does not exist.', 0, null, ['method' => $method, 'id' => $id]);
            }

            $this->container->call([$object, $method], $this->resolveValue($callArguments));
        }

        return $object;
    }

    protected function factory(mixed $factory, string $id): callable|array|string
    {
        if (is_string($factory)) {
            return str_starts_with($factory, '@') ? $this->container->get(substr($factory, 1)) : $factory;
        }

        if (is_array($factory) && count($factory) === 2) {
            $target = is_string($factory[0]) && str_starts_with($factory[0], '@') ? $this->container->get(substr($factory[0], 1)) : $factory[0];

            return [$target, (string) $factory[1]];
        }

        throw new ServiceException('The factory of the service "{id}" must be "Class::method", ["@service", "method"] or ["Class", "method"].', 0, null, ['id' => $id]);
    }

    protected function resolveValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->resolveValue($item), $value);
        }

        if (!is_string($value)) {
            return $value;
        }

        if (str_starts_with($value, '@@')) {
            return substr($value, 1);
        }

        if (str_starts_with($value, '@?')) {
            $id = substr($value, 2);

            return $this->container->has($id) ? $this->container->get($id) : null;
        }

        if (str_starts_with($value, '@')) {
            return $this->container->get(substr($value, 1));
        }

        if (str_contains($value, '%') && $this->resolver !== null) {
            return ($this->resolver)($value);
        }

        return $value;
    }
}