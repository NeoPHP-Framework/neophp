<?php

declare(strict_types=1);

namespace NeoPHP\Component\Container;

use NeoPHP\Component\Container\Exception\ContainerException;
use NeoPHP\Component\Container\Exception\NotFoundException;

interface ContainerManagerInterface
{
    /**
     * Returns an entry, built on first use; a singleton or an autowired shared class is returned as the same instance on every call.
     *
     * @param string $id Identifier of the entry: a class, an interface, an alias or a parameter name
     * @return mixed The entry
     * @throws NotFoundException When the entry is not bound and is not an existing class
     * @throws ContainerException When the alias is circular, the class is not instantiable, has a circular dependency or an argument that cannot be resolved
     */
    public function get(string $id): mixed;

    /**
     * Tells whether an entry can be returned: bound, an instance, or an instantiable class.
     *
     * @param string $id Identifier of the entry
     * @return bool True when the entry can be returned
     * @throws ContainerException When the alias is circular
     */
    public function has(string $id): bool;

    /**
     * Binds an entry to a concrete value, replacing its previous binding, instance or alias.
     *
     * @param string $id Identifier of the entry
     * @param mixed $concrete A class name, another identifier, a closure receiving the container and the parameters, or a value; the identifier itself when null
     * @param bool $shared Builds the entry once and returns the same instance on every get()
     * @return static The container
     */
    public function bind(string $id, mixed $concrete = null, bool $shared = false): static;

    /**
     * Binds a shared entry, built once on first use.
     *
     * @param string $id Identifier of the entry
     * @param mixed $concrete A class name, another identifier, a closure receiving the container and the parameters, or a value; the identifier itself when null
     * @return static The container
     */
    public function singleton(string $id, mixed $concrete = null): static;

    /**
     * Registers an existing value or object as an entry.
     *
     * @param string $id Identifier of the entry
     * @param mixed $value The object or the value
     * @return static The container
     */
    public function instance(string $id, mixed $value): static;

    /**
     * Makes an identifier an alias of another entry.
     *
     * @param string $alias The alias
     * @param string $id Identifier of the aliased entry
     * @return static The container
     * @throws ContainerException When the alias is the identifier itself or creates a circular alias
     */
    public function alias(string $alias, string $id): static;

    /**
     * Tells whether an entry is bound or registered as an instance, without autowiring.
     *
     * @param string $id Identifier of the entry
     * @return bool True when the entry is bound or registered
     * @throws ContainerException When the alias is circular
     */
    public function bound(string $id): bool;

    /**
     * Tells whether an entry is already built or registered as an instance.
     *
     * @param string $id Identifier of the entry
     * @return bool True when the instance exists
     * @throws ContainerException When the alias is circular
     */
    public function resolved(string $id): bool;

    /**
     * Builds a new instance of an entry, even when it is shared, without storing it.
     *
     * @param string $id Identifier of the entry
     * @param array<mixed> $parameters Constructor arguments by name or position, or by class for a typed argument
     * @return mixed The new entry
     * @throws NotFoundException When the entry is not bound and is not an existing class
     * @throws ContainerException When the alias is circular, the class is not instantiable, has a circular dependency or an argument that cannot be resolved
     */
    public function make(string $id, array $parameters = []): mixed;

    /**
     * Builds a new instance of a class with autowiring, ignoring its bindings.
     *
     * @param string $class Name of the class
     * @param array<mixed> $parameters Constructor arguments by name or position, or by class for a typed argument
     * @return object The new instance
     * @throws NotFoundException When the class does not exist
     * @throws ContainerException When the class is not instantiable, has a circular dependency or an argument that cannot be resolved
     */
    public function instantiate(string $class, array $parameters = []): object;

    /**
     * Sets the properties of an object that carry the #[Inject] attribute, parent classes included.
     *
     * @param object $object The object
     * @return object The same object
     * @throws NotFoundException When an injected service does not exist
     * @throws ContainerException When an injected property has no class type nor service, or its service cannot be built
     */
    public function inject(object $object): object;

    /**
     * Calls a callable with its arguments resolved from the parameters and the container.
     *
     * @param callable|array{0: object|string, 1: string}|string $callable A closure, a function name, an invokable class or object, [class or object, method] or "Class::method"; a class name is built by the container
     * @param array<mixed> $parameters Arguments by name or position, or by class for a typed argument
     * @return mixed The value returned by the callable
     * @throws NotFoundException When a service required by the callable does not exist
     * @throws ContainerException When the value is not a valid callable, the method does not exist, or an argument cannot be resolved
     */
    public function call(callable|array|string $callable, array $parameters = []): mixed;

    /**
     * Returns the bound entries and the registered instances, sorted by identifier.
     *
     * @return array<array{kind: string, concrete: string, class: string|null, resolved: bool}> Every entry by identifier: kind (singleton, factory, instance or parameter), concrete, class of the built instance and whether it is built
     */
    public function getDefinitions(): array;

    /**
     * Returns the aliases, sorted by alias.
     *
     * @return array<string, string> The aliased identifier, by alias
     */
    public function getAliases(): array;
}