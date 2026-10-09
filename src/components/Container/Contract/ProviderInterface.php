<?php

declare(strict_types=1);

namespace NeoPHP\Component\Container\Contract;

use NeoPHP\Component\Container\ContainerManagerInterface;

interface ProviderInterface
{
    public function register(ContainerManagerInterface $container): void;

    public function boot(ContainerManagerInterface $container): void;
}