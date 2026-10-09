<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Provider;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Kernel\KernelManager;
use NeoPHP\Component\Kernel\KernelManagerInterface;

/**
 * @internal
 */
class KernelProvider extends AbstractProvider
{
    public function register(ContainerManagerInterface $container): void
    {
        if ($container->bound(KernelManagerInterface::class) && !$container->bound(KernelManager::class)) {
            $container->alias(KernelManager::class, KernelManagerInterface::class);
        }
    }
}