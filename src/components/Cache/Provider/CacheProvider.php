<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cache\Provider;

use NeoPHP\Component\Cache\CacheManager;
use NeoPHP\Component\Cache\Contract\CacheInterface;
use NeoPHP\Component\Cache\Contract\CacheManagerInterface;
use NeoPHP\Component\Config\Contract\ConfigInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Database\Contract\ConnectionInterface;
use NeoPHP\Component\Database\Contract\DatabaseInterface;

class CacheProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.cache';

    public const POOL_PREFIX = 'cache.';

    public function register(ContainerInterface $container): void
    {
        $container->singleton(CacheManagerInterface::class, static function (ContainerInterface $container): CacheManagerInterface {
            $rootPath = $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : (string) getcwd();
            $connections = $container->has(DatabaseInterface::class)
                ? static fn (?string $name): ConnectionInterface => $container->get(DatabaseInterface::class)->connection($name)
                : null;

            $secret = $container->has(ConfigInterface::class) ? (string) ($container->get(ConfigInterface::class)->get('framework.app.secret', '') ?? '') : '';

            return new CacheManager(self::config($container), $rootPath, $connections, $secret !== '' ? $secret : null);
        });

        $container->alias(CacheManager::class, CacheManagerInterface::class);
        $container->alias(CacheInterface::class, CacheManagerInterface::class);
        $container->alias('cache', CacheManagerInterface::class);

        $pools = array_keys((array) (self::config($container)['pools'] ?? [])) ?: array_keys(CacheManager::DEFAULT_CONFIG['pools']);

        foreach ($pools as $name) {
            $name = (string) $name;
            $container->singleton(self::POOL_PREFIX . $name, static fn (ContainerInterface $container): CacheInterface => $container->get(CacheManagerInterface::class)->pool($name));
        }
    }

    public static function config(ContainerInterface $container): array
    {
        return $container->has(ConfigInterface::class) ? (array) $container->get(ConfigInterface::class)->get(self::CONFIG_KEY, []) : [];
    }
}