<?php

declare(strict_types=1);

namespace NeoPHP\Component\Service;

use NeoPHP\Component\Container\Exception\ContainerException;

interface ServiceManagerInterface
{
    /**
     * Registers the services, aliases and interfaces of config/services.yaml in the container; a service is built on first use with its arguments, factory and calls.
     *
     * @param array<string, mixed> $definitions The normalized definitions: services (id => class, arguments, calls, factory, shared), aliases (alias => id) and interfaces (interface => implementing service ids)
     * @return static The service manager
     * @throws ContainerException When an alias is circular or an alias of itself
     */
    public function register(array $definitions): static;

    /**
     * Returns the registered service definitions.
     *
     * @return array<string, mixed> The definition of every service, by id
     */
    public function getServices(): array;

    /**
     * Returns the registered aliases.
     *
     * @return array<string, string> The target service, by alias
     */
    public function getAliases(): array;

    /**
     * Returns the interfaces bound automatically to the services implementing them.
     *
     * @return array<string, string|list<string>> The implementing service, or the list of services when several implement it, by interface
     */
    public function getInterfaces(): array;
}