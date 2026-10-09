<?php

declare(strict_types=1);

namespace NeoPHP\Component\Container\Provider;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;

/**
 * @internal
 */
class ContainerProvider extends AbstractProvider
{
    public function register(ContainerManagerInterface $container): void
    {
        if (!$container->bound(ContainerManagerInterface::class)) {
            $container->instance(ContainerManagerInterface::class, $container);
        }
    }
}