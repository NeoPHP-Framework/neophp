<?php

declare(strict_types=1);

namespace NeoPHP\Process\Installer\Provider;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Process\Installer\InstallerManager;
use NeoPHP\Process\Installer\InstallerManagerInterface;

/**
 * @internal
 */
class InstallerProvider extends AbstractProvider
{
    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(InstallerManagerInterface::class, InstallerManager::class);
        $container->alias(InstallerManager::class, InstallerManagerInterface::class);
    }
}