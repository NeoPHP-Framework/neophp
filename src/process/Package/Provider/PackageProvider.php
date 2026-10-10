<?php

declare(strict_types=1);

namespace NeoPHP\Process\Package\Provider;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Process\Package\PackageManager;
use NeoPHP\Process\Package\PackageManagerInterface;

/**
 * @internal
 */
class PackageProvider extends AbstractProvider
{
    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(PackageManagerInterface::class, static function (ContainerManagerInterface $container): PackageManagerInterface {
            return new PackageManager($container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : (string) getcwd());
        });

        $container->alias(PackageManager::class, PackageManagerInterface::class);
    }
}