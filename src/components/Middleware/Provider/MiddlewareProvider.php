<?php

declare(strict_types=1);

namespace NeoPHP\Component\Middleware\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Kernel\Cache\ResourceCache;
use NeoPHP\Component\Middleware\Discovery\MiddlewareDiscovery;
use NeoPHP\Component\Middleware\MiddlewareManager;
use NeoPHP\Component\Middleware\MiddlewareManagerInterface;

/**
 * @internal
 */
class MiddlewareProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.middleware';

    public const CACHE_DIRECTORY = 'middleware';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(MiddlewareManagerInterface::class, static function (ContainerManagerInterface $container): MiddlewareManagerInterface {
            $config = $container->has(ConfigManagerInterface::class) ? (array) $container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) : [];
            $discovered = self::discover($container);

            return new MiddlewareManager(
                $container,
                [...(array) ($config['global'] ?? []), ...$discovered['global']],
                [...$discovered['aliases'], ...(array) ($config['aliases'] ?? [])],
                (array) ($config['groups'] ?? []),
            );
        });

        $container->alias(MiddlewareManager::class, MiddlewareManagerInterface::class);
    }

    protected static function discover(ContainerManagerInterface $container): array
    {
        if (!$container->has('kernel.root_path')) {
            return ['aliases' => [], 'global' => []];
        }

        $source = (string) $container->get('kernel.root_path') . DIRECTORY_SEPARATOR . 'src';
        $builder = static function () use ($source): array {
            $discovery = new MiddlewareDiscovery([$source]);

            return [$discovery->discover(), $discovery->getResources()];
        };

        if (!$container->has('kernel.cache_path')) {
            return $builder()[0];
        }

        $environment = $container->has('kernel.environment') ? (string) $container->get('kernel.environment') : 'dev';
        $debug = $container->has('kernel.debug') && (bool) $container->get('kernel.debug');
        $file = (string) $container->get('kernel.cache_path') . DIRECTORY_SEPARATOR . self::CACHE_DIRECTORY . DIRECTORY_SEPARATOR . 'middlewares.' . $environment . '.php';

        return (new ResourceCache($file, $debug))->load($builder) + ['aliases' => [], 'global' => []];
    }
}