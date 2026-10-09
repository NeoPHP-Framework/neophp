<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Provider;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Validator\ValidatorManager;
use NeoPHP\Component\Validator\ValidatorManagerInterface;

/**
 * @internal
 */
class ValidatorProvider extends AbstractProvider
{
    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(ValidatorManagerInterface::class, static fn (ContainerManagerInterface $container): ValidatorManagerInterface => new ValidatorManager($container));
        $container->alias(ValidatorManager::class, ValidatorManagerInterface::class);
    }
}