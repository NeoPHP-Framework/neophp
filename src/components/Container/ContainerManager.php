<?php

declare(strict_types=1);

namespace NeoPHP\Component\Container;

use Closure;
use NeoPHP\Component\Container\Attribute\Autowire;
use NeoPHP\Component\Container\Attribute\Inject;
use NeoPHP\Component\Container\Exception\ContainerException;
use NeoPHP\Component\Container\Exception\NotFoundException;
use NeoPHP\Component\Container\Provider\ContainerProvider;
use NeoPHP\Component\Kernel\Attribute\Component;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use stdClass;

#[Component(provider: ContainerProvider::class)]
final class ContainerManager implements ContainerManagerInterface
{
    public const CONFIG_ID = 'config';

    protected bool $autoShare = true;

    protected array $bindings = [];

    protected array $instances = [];

    protected array $aliases = [];

    private array $building = [];

    public function __construct()
    {
        $this->instance(self::class, $this);
        $this->alias(ContainerManagerInterface::class, self::class);
    }

    public function bind(string $id, mixed $concrete = null, bool $shared = false): static
    {
        unset($this->instances[$id], $this->aliases[$id]);
        $this->bindings[$id] = ['concrete' => $concrete ?? $id, 'shared' => $shared];

        return $this;
    }

    public function singleton(string $id, mixed $concrete = null): static
    {
        return $this->bind($id, $concrete, true);
    }

    public function instance(string $id, mixed $value): static
    {
        unset($this->aliases[$id]);
        $this->instances[$id] = $value;

        return $this;
    }

    public function alias(string $alias, string $id): static
    {
        if ($alias === $id) {
            throw new ContainerException(sprintf('"%s" cannot be aliased to itself.', $id));
        }

        $this->aliases[$alias] = $id;

        try {
            $this->resolveAlias($alias);
        } catch (ContainerException $exception) {
            unset($this->aliases[$alias]);

            throw $exception;
        }

        return $this;
    }

    public function bound(string $id): bool
    {
        $id = $this->resolveAlias($id);

        return isset($this->bindings[$id]) || array_key_exists($id, $this->instances);
    }

    public function resolved(string $id): bool
    {
        return array_key_exists($this->resolveAlias($id), $this->instances);
    }

    public function getDefinitions(): array
    {
        $definitions = [];

        foreach ($this->bindings as $id => $binding) {
            $concrete = $binding['concrete'];
            $definitions[$id] = [
                'kind' => $binding['shared'] ? 'singleton' : 'factory',
                'concrete' => match (true) {
                    $concrete instanceof Closure => 'closure',
                    is_string($concrete) => $concrete,
                    default => get_debug_type($concrete),
                },
                'class' => array_key_exists($id, $this->instances) ? get_debug_type($this->instances[$id]) : null,
                'resolved' => array_key_exists($id, $this->instances),
            ];
        }

        foreach ($this->instances as $id => $instance) {
            if (!isset($definitions[$id])) {
                $definitions[$id] = [
                    'kind' => is_object($instance) ? 'instance' : 'parameter',
                    'concrete' => get_debug_type($instance),
                    'class' => get_debug_type($instance),
                    'resolved' => true,
                ];
            }
        }

        ksort($definitions);

        return $definitions;
    }

    public function getAliases(): array
    {
        $aliases = $this->aliases;
        ksort($aliases);

        return $aliases;
    }

    public function has(string $id): bool
    {
        if ($this->bound($id)) {
            return true;
        }

        $id = $this->resolveAlias($id);

        return class_exists($id) && (new ReflectionClass($id))->isInstantiable();
    }

    public function get(string $id): mixed
    {
        return $this->resolve($id, [], false);
    }

    public function make(string $id, array $parameters = []): mixed
    {
        return $this->resolve($id, $parameters, true);
    }

    public function instantiate(string $class, array $parameters = []): object
    {
        return $this->build($class, $parameters, $class);
    }

    public function inject(object $object): object
    {
        $class = new ReflectionClass($object);

        do {
            foreach ($class->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }

                $attributes = $property->getAttributes(Inject::class);

                if ($attributes !== []) {
                    /** @var Inject $attributes */
                    $instance = $attributes[0]->newInstance();
                    $property->setValue($object, $this->injectedValue($instance, $property));
                }
            }

            $class = $class->getParentClass();
        } while ($class !== false);

        return $object;
    }

    public function call(callable|array|string $callable, array $parameters = []): mixed
    {
        if (is_string($callable) && str_contains($callable, '::')) {
            $callable = explode('::', $callable, 2);
        }

        if (is_string($callable) && class_exists($callable)) {
            $callable = [$callable, '__invoke'];
        }

        try {
            if (is_array($callable)) {
                [$target, $method] = $callable;

                if (is_string($target)) {
                    $reflection = new ReflectionMethod($target, $method);
                    $target = $reflection->isStatic() ? null : $this->get($target);
                } else {
                    $reflection = new ReflectionMethod($target, $method);
                }

                return $reflection->invokeArgs($target, $this->resolveArguments($reflection, $parameters));
            }

            if ($callable instanceof Closure || is_string($callable)) {
                $reflection = new ReflectionFunction($callable);

                return $reflection->invokeArgs($this->resolveArguments($reflection, $parameters));
            }

            if (is_object($callable) && method_exists($callable, '__invoke')) {
                $reflection = new ReflectionMethod($callable, '__invoke');

                return $reflection->invokeArgs($callable, $this->resolveArguments($reflection, $parameters));
            }
        } catch (ReflectionException $exception) {
            throw new ContainerException($exception->getMessage(), 0, $exception);
        }

        throw new ContainerException('The given value is not a valid callable.');
    }

    public function resolveArguments(ReflectionFunctionAbstract $function, array $parameters = []): array
    {
        $arguments = [];

        foreach ($function->getParameters() as $position => $parameter) {
            $name = $parameter->getName();

            if (!array_key_exists($name, $parameters) && array_key_exists($position, $parameters)) {
                $parameters[$name] = $parameters[$position];
            }

            if (array_key_exists($name, $parameters)) {
                if ($parameter->isVariadic() && is_array($parameters[$name])) {
                    array_push($arguments, ...array_values($parameters[$name]));
                    break;
                }

                $arguments[] = $parameters[$name];
                continue;
            }

            if ($parameter->isVariadic()) {
                break;
            }

            $arguments[] = $this->resolveParameter($parameter, $parameters, $function);
        }

        return $arguments;
    }

    protected function resolveParameter(ReflectionParameter $parameter, array $parameters, ReflectionFunctionAbstract $function): mixed
    {
        $autowire = $parameter->getAttributes(Autowire::class);

        if ($autowire !== []) {
            /** @var Autowire $instance */
            $instance = $autowire[0]->newInstance();
            return $this->autowiredValue($instance, $parameter->allowsNull(), '$' . $parameter->getName());
        }

        foreach ($this->classTypesOf($parameter) as $class) {
            if (array_key_exists($class, $parameters)) {
                return $parameters[$class];
            }

            if ($this->has($class)) {
                try {
                    return $this->get($class);
                } catch (NotFoundException $exception) {
                    if (!$parameter->isOptional() && !$parameter->allowsNull()) {
                        throw $exception;
                    }
                }
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull() && $parameter->hasType()) {
            return null;
        }

        $owner = $function instanceof ReflectionMethod
            ? $function->getDeclaringClass()->getName() . '::' . $function->getName()
            : $function->getName();

        throw new ContainerException(sprintf(
            'Unable to resolve parameter "$%s" of "%s()"%s.',
            $parameter->getName(),
            $owner,
            $this->classTypesOf($parameter) === [] ? ': no class type-hint and no default value' : ': no entry found for its type',
        ));
    }

    protected function resolve(string $id, array $parameters, bool $forceNew): mixed
    {
        $id = $this->resolveAlias($id);

        if (!$forceNew && array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        $binding = $this->bindings[$id] ?? null;

        if ($binding === null && !class_exists($id)) {
            throw NotFoundException::forId($id);
        }

        $object = $this->build($binding['concrete'] ?? $id, $parameters, $id, $forceNew);
        $shared = $binding !== null ? $binding['shared'] : $this->autoShared($id);

        if (!$forceNew && $shared) {
            $this->instances[$id] = $object;
        }

        return $object;
    }

    protected function build(mixed $concrete, array $parameters, string $id, bool $forceNew = false): mixed
    {
        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }

        if (!is_string($concrete)) {
            return $concrete;
        }

        if (!class_exists($concrete)) {
            if ($concrete !== $id) {
                return $this->resolve($concrete, $parameters, $forceNew);
            }

            throw NotFoundException::forId($concrete);
        }

        if (isset($this->building[$concrete])) {
            throw new ContainerException(sprintf(
                'Circular reference detected while building "%s" (%s).',
                $concrete,
                implode(' -> ', [...array_keys($this->building), $concrete]),
            ));
        }

        $reflection = new ReflectionClass($concrete);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException(sprintf('Class "%s" is not instantiable (bind a concrete implementation for "%s").', $concrete, $id));
        }

        $this->building[$concrete] = true;

        try {
            $constructor = $reflection->getConstructor();

            $object = $constructor === null ? new $concrete() : $reflection->newInstanceArgs($this->resolveArguments($constructor, $parameters));

            return $this->inject($object);
        } finally {
            unset($this->building[$concrete]);
        }
    }

    protected function autoShared(string $class): bool
    {
        if (!$this->autoShare || !class_exists($class)) {
            return false;
        }

        $attributes = (new ReflectionClass($class))->getAttributes(Autowire::class);

        if ($attributes !== []) {
            $shared = $attributes[0]->newInstance()->shared;

            if ($shared !== null) {
                return $shared;
            }
        }

        return true;
    }

    protected function injectedValue(Inject $inject, ReflectionProperty $property): mixed
    {
        $label = $property->getDeclaringClass()->getName() . '::$' . $property->getName();

        if ($inject->service !== null || $inject->config !== null || $inject->env !== null || $inject->param !== null || $inject->value !== null) {
            return $this->autowiredValue(new Autowire($inject->value, $inject->service, $inject->config, $inject->env, $inject->param), $property->getType()?->allowsNull() ?? true, $label);
        }

        $type = $property->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            if ($this->has($type->getName())) {
                return $this->get($type->getName());
            }

            if ($type->allowsNull()) {
                return null;
            }
        }

        throw new ContainerException(sprintf('Unable to inject "%s": add a class type or a service to #[Inject].', $label));
    }

    protected function autowiredValue(Autowire $autowire, bool $nullable, string $label): mixed
    {
        if ($autowire->service !== null) {
            if (!$this->has($autowire->service) && $nullable) {
                return null;
            }

            return $this->get($autowire->service);
        }

        if ($autowire->param !== null) {
            if (!$this->bound($autowire->param)) {
                if ($nullable) {
                    return null;
                }

                throw new ContainerException(sprintf('The parameter "%s" required by "%s" is not defined.', $autowire->param, $label));
            }

            return $this->get($autowire->param);
        }

        if ($autowire->config !== null) {
            $missing = new stdClass();
            $value = $this->bound(self::CONFIG_ID) ? $this->get(self::CONFIG_ID)->get($autowire->config, $missing) : $missing;

            if ($value === $missing) {
                if ($nullable) {
                    return null;
                }

                throw new ContainerException(sprintf('The configuration key "%s" required by "%s" is not defined.', $autowire->config, $label));
            }

            return $value;
        }

        if ($autowire->env !== null) {
            $value = $_SERVER[$autowire->env] ?? $_ENV[$autowire->env] ?? getenv($autowire->env);

            if ($value === false) {
                if ($nullable) {
                    return null;
                }

                throw new ContainerException(sprintf('The environment variable "%s" required by "%s" is not defined.', $autowire->env, $label));
            }

            return $value;
        }

        if (is_string($autowire->value) && str_contains($autowire->value, '%') && $this->bound(self::CONFIG_ID)) {
            return $this->get(self::CONFIG_ID)->resolve($autowire->value);
        }

        return $autowire->value;
    }

    protected function resolveAlias(string $id): string
    {
        $seen = [];

        while (isset($this->aliases[$id])) {
            if (isset($seen[$id])) {
                throw new ContainerException(sprintf('Circular alias detected for "%s".', $id));
            }

            $seen[$id] = true;
            $id = $this->aliases[$id];
        }

        return $id;
    }

    private function classTypesOf(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();

        if ($type === null) {
            return [];
        }

        $types = $type instanceof ReflectionNamedType ? [$type] : (method_exists($type, 'getTypes') ? $type->getTypes() : []);
        $classes = [];

        foreach ($types as $named) {
            if (!$named instanceof ReflectionNamedType || $named->isBuiltin()) {
                continue;
            }

            $name = $named->getName();

            if ($name === 'self' || $name === 'static') {
                $declaring = $parameter->getDeclaringClass();
                $name = $declaring?->getName() ?? $name;
            }

            $classes[] = $name;
        }

        return $classes;
    }
}