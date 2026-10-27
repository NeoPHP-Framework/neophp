<?php

declare(strict_types=1);

namespace NeoPHP\Package\Mercure\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Cookie\CookieManagerInterface;
use NeoPHP\Component\HttpClient\HttpClientManagerInterface;
use NeoPHP\Package\Mercure\MercureManager;
use NeoPHP\Package\Mercure\MercureManagerInterface;
use NeoPHP\Package\Queue\Contract\MessageBusInterface;

/**
 * @internal
 */
class MercureProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'packages.mercure';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(MercureManagerInterface::class, static fn (ContainerManagerInterface $container): MercureManagerInterface => new MercureManager(
            $container->get(HttpClientManagerInterface::class),
            self::config($container),
            $container->has(CookieManagerInterface::class) ? $container->get(CookieManagerInterface::class) : null,
            $container->has(MessageBusInterface::class) ? $container->get(MessageBusInterface::class) : null,
        ));

        $container->alias(MercureManager::class, MercureManagerInterface::class);
        $container->alias('mercure', MercureManagerInterface::class);
    }

    protected static function config(ContainerManagerInterface $container): array
    {
        return $container->has(ConfigManagerInterface::class) ? (array) ($container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) ?? []) : [];
    }
}