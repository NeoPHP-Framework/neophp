<?php

declare(strict_types=1);

namespace NeoPHP\Component\Csrf\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Csrf\CsrfManager;
use NeoPHP\Component\Csrf\CsrfManagerInterface;
use NeoPHP\Component\Session\SessionManagerInterface;

/**
 * @internal
 */
class CsrfProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.csrf';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(CsrfManagerInterface::class, static function (ContainerManagerInterface $container): CsrfManagerInterface {
            $config = $container->has(ConfigManagerInterface::class) ? (array) $container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) : [];

            return new CsrfManager($container->get(SessionManagerInterface::class), $config);
        });

        $container->alias(CsrfManager::class, CsrfManagerInterface::class);
        $container->alias('csrf', CsrfManagerInterface::class);
    }
}