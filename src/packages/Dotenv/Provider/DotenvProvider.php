<?php

declare(strict_types=1);

namespace NeoPHP\Package\Dotenv\Provider;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Package\Dotenv\DotenvManager;
use NeoPHP\Package\Dotenv\DotenvManagerInterface;

/**
 * @internal
 */
class DotenvProvider extends AbstractProvider
{
    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(DotenvManagerInterface::class, DotenvManager::class);
        $container->alias(DotenvManager::class, DotenvManagerInterface::class);
    }
}