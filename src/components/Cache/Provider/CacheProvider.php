<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cache\Provider;

use NeoPHP\Component\Cache\CacheManager;
use NeoPHP\Component\Cache\CacheManagerInterface;
use NeoPHP\Component\Cache\Contract\CacheInterface;
use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Database\Contract\ConnectionInterface;
use NeoPHP\Component\Database\DatabaseManagerInterface;

/**
 * @internal
 */
class CacheProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.cache';

    public const POOL_PREFIX = 'cache.';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(CacheManagerInterface::class, static function (ContainerManagerInterface $container): CacheManagerInterface {
            $rootPath = $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : (string) getcwd();
            $connections = $container->has(DatabaseManagerInterface::class)
                ? static fn (?string $name): ConnectionInterface => $container->get(DatabaseManagerInterface::class)->connection($name)
                : null;

            $secret = $container->has(ConfigManagerInterface::class) ? (string) ($container->get(ConfigManagerInterface::class)->get('framework.app.secret', '') ?? '') : '';

            return new CacheManager(self::config($container), $rootPath, $connections, $secret !== '' ? $secret : null);
        });

        $container->alias(CacheManager::class, CacheManagerInterface::class);
        $container->alias(CacheInterface::class, CacheManagerInterface::class);
        $container->alias('cache', CacheManagerInterface::class);

        $pools = array_keys((array) (self::config($container)['pools'] ?? [])) ?: array_keys(CacheManager::DEFAULT_CONFIG['pools']);

        foreach ($pools as $name) {
            $name = (string) $name;
            $container->singleton(self::POOL_PREFIX . $name, static fn (ContainerManagerInterface $container): CacheInterface => $container->get(CacheManagerInterface::class)->pool($name));
        }
    }

    public static function config(ContainerManagerInterface $container): array
    {
        return $container->has(ConfigManagerInterface::class) ? (array) $container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) : [];
    }
}