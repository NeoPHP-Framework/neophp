<?php

declare(strict_types=1);

namespace NeoPHP\Component\Event\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Event\Discovery\ListenerDiscovery;
use NeoPHP\Component\Event\EventManager;
use NeoPHP\Component\Event\EventManagerInterface;
use NeoPHP\Component\Kernel\Cache\ResourceCache;
use NeoPHP\Component\Kernel\KernelManagerInterface;
use NeoPHP\Component\Kernel\Module\InstalledPackages;
use NeoPHP\Component\Kernel\Module\ModuleSources;

/**
 * @internal
 */
class EventProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.event';

    public const CACHE_DIRECTORY = 'event';

    public const FRAMEWORK_SOURCES = ['components', 'packages', 'process'];

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(EventManagerInterface::class, static function (ContainerManagerInterface $container): EventManagerInterface {
            $dispatcher = new EventManager($container);
            $config = $container->has(ConfigManagerInterface::class) ? (array) $container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) : [];

            foreach ((array) ($config['listeners'] ?? []) as $event => $listeners) {
                foreach ((array) $listeners as $listener) {
                    $listener = is_array($listener) ? $listener : ['listener' => $listener];
                    $dispatcher->addListener((string) $event, [(string) $listener['listener'], (string) ($listener['method'] ?? '__invoke')], (int) ($listener['priority'] ?? 0));
                }
            }

            foreach ((array) ($config['subscribers'] ?? []) as $subscriber) {
                $dispatcher->addSubscriber((string) $subscriber);
            }

            $kernel = $container->bound(KernelManagerInterface::class) ? $container->get(KernelManagerInterface::class) : null;

            foreach (self::discover($container) as $event => $listeners) {
                foreach ((array) $listeners as [$class, $method, $priority]) {
                    if ($kernel !== null && !$kernel->isEnabled((string) $class)) {
                        continue;
                    }

                    $dispatcher->addListener((string) $event, [(string) $class, (string) $method], (int) $priority);
                }
            }

            return $dispatcher;
        });

        $container->alias(EventManager::class, EventManagerInterface::class);
    }

    protected static function discover(ContainerManagerInterface $container): array
    {
        $paths = [];
        $frameworkPath = dirname(__DIR__, 3);

        foreach (self::FRAMEWORK_SOURCES as $directory) {
            $paths[] = $frameworkPath . DIRECTORY_SEPARATOR . $directory;
        }

        $resources = [];

        if ($container->has('kernel.root_path')) {
            $rootPath = (string) $container->get('kernel.root_path');
            $paths[] = $rootPath . DIRECTORY_SEPARATOR . 'src';

            $installed = InstalledPackages::file($rootPath);
            $resources[$installed] = is_file($installed) ? (int) filemtime($installed) : ResourceCache::MISSING;
        }

        $kernel = $container->bound(KernelManagerInterface::class) ? $container->get(KernelManagerInterface::class) : null;

        foreach (array_keys(ModuleSources::external($kernel)) as $directory) {
            $paths[] = (string) $directory;
        }

        $builder = static function () use ($paths, $resources): array {
            $discovery = new ListenerDiscovery($paths);

            return [$discovery->discover(), $discovery->getResources() + $resources];
        };

        if (!$container->has('kernel.cache_path')) {
            return $builder()[0];
        }

        $environment = $container->has('kernel.environment') ? (string) $container->get('kernel.environment') : 'dev';
        $debug = $container->has('kernel.debug') && (bool) $container->get('kernel.debug');
        $file = (string) $container->get('kernel.cache_path') . DIRECTORY_SEPARATOR . self::CACHE_DIRECTORY . DIRECTORY_SEPARATOR . 'listeners.' . $environment . '.php';

        return (new ResourceCache($file, $debug))->load($builder);
    }
}