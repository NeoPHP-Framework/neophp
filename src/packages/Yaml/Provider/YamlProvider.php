<?php

declare(strict_types=1);

namespace NeoPHP\Package\Yaml\Provider;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Package\Yaml\YamlManager;
use NeoPHP\Package\Yaml\YamlManagerInterface;

/**
 * @internal
 */
class YamlProvider extends AbstractProvider
{
    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(YamlManagerInterface::class, YamlManager::class);
        $container->alias(YamlManager::class, YamlManagerInterface::class);
    }
}