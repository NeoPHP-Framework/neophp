<?php

declare(strict_types=1);

namespace NeoPHP\Component\Container\Helper\Controller;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Exception\ContainerException;

trait ContainerController
{
    protected ?ContainerManagerInterface $container = null;

    public function setContainer(ContainerManagerInterface $container): void
    {
        $this->container = $container;
    }

    protected function get(string $id): mixed
    {
        if ($this->container === null) {
            throw new ContainerException('The container is not set on "{controller}".', 0, null, ['controller' => static::class]);
        }

        return $this->container->get($id);
    }

    protected function has(string $id): bool
    {
        return $this->container !== null && $this->container->has($id);
    }
}