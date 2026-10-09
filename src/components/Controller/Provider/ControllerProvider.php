<?php

declare(strict_types=1);

namespace NeoPHP\Component\Controller\Provider;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Controller\ControllerManager;
use NeoPHP\Component\Controller\ControllerManagerInterface;

/**
 * @internal
 */
class ControllerProvider extends AbstractProvider
{
    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(ControllerManagerInterface::class, ControllerManager::class);
        $container->alias(ControllerManager::class, ControllerManagerInterface::class);
    }
}