<?php

declare(strict_types=1);

namespace NeoPHP\Component\Exception\Provider;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Exception\ExceptionManager;
use NeoPHP\Component\Exception\ExceptionManagerInterface;

/**
 * @internal
 */
class ExceptionProvider extends AbstractProvider
{
    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(ExceptionManager::class, static function (ContainerManagerInterface $container): ExceptionManager {
            return new ExceptionManager($container->has('kernel.debug') && (bool) $container->get('kernel.debug'));
        });

        $container->alias(ExceptionManagerInterface::class, ExceptionManager::class);
    }
}