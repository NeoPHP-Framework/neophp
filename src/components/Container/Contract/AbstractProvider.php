<?php

declare(strict_types=1);

namespace NeoPHP\Component\Container\Contract;

use NeoPHP\Component\Container\ContainerManagerInterface;

abstract class AbstractProvider implements ProviderInterface
{
    public function boot(ContainerManagerInterface $container): void
    {
    }
}