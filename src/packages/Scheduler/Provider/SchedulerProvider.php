<?php

declare(strict_types=1);

namespace NeoPHP\Package\Scheduler\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Kernel\Cache\ResourceCache;
use NeoPHP\Component\Kernel\Module\ModuleSources;
use NeoPHP\Package\Scheduler\Discovery\TaskDiscovery;
use NeoPHP\Package\Scheduler\History\HistoryStore;
use NeoPHP\Package\Scheduler\Lock\LockStore;
use NeoPHP\Package\Scheduler\Runner\TaskRunner;
use NeoPHP\Package\Scheduler\SchedulerManager;
use NeoPHP\Package\Scheduler\SchedulerManagerInterface;

/**
 * @internal
 */
class SchedulerProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'packages.scheduler';

    public const CONFIG_ID = 'scheduler.config';

    public const CACHE_DIRECTORY = 'scheduler';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(self::CONFIG_ID, static fn (ContainerManagerInterface $container): array => self::configure($container));

        $container->singleton(SchedulerManagerInterface::class, static function (ContainerManagerInterface $container): SchedulerManagerInterface {
            $config = $container->get(self::CONFIG_ID);
            $discovered = self::discover($container);

            return new SchedulerManager(
                (array) $config['tasks'],
                (array) ($discovered['tasks'] ?? []),
                [...(array) ($discovered['providers'] ?? []), ...(array) $config['providers']],
                $container,
                $config['timezone'],
            );
        });

        $container->singleton(LockStore::class, static fn (ContainerManagerInterface $container): LockStore => new LockStore((string) $container->get(self::CONFIG_ID)['lock_path']));

        $container->singleton(HistoryStore::class, static function (ContainerManagerInterface $container): HistoryStore {
            $config = $container->get(self::CONFIG_ID);

            return new HistoryStore((string) $config['history_file'], (int) $config['history_max'], (string) $config['heartbeat_file']);
        });

        $container->singleton(TaskRunner::class, static function (ContainerManagerInterface $container): TaskRunner {
            $config = $container->get(self::CONFIG_ID);

            return new TaskRunner(
                $container->get(LockStore::class),
                $container->get(HistoryStore::class),
                $container,
                (string) $config['root_path'],
                (string) $config['console'],
                $config['php_binary'],
            );
        });

        $container->alias(SchedulerManager::class, SchedulerManagerInterface::class);
        $container->alias('scheduler', SchedulerManagerInterface::class);
    }

    public static function configure(ContainerManagerInterface $container): array
    {
        $config = $container->has(ConfigManagerInterface::class) ? (array) ($container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) ?? []) : [];
        $root = $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : (string) getcwd();
        $storage = (string) ($config['storage'] ?? '');
        $storage = $storage === '' ? $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'scheduler' : (preg_match('#^([A-Za-z]:)?[/\\\\]#', $storage) === 1 ? $storage : $root . DIRECTORY_SEPARATOR . $storage);
        $binary = (string) ($config['php_binary'] ?? '');

        return [
            'timezone' => isset($config['timezone']) && $config['timezone'] !== '' ? (string) $config['timezone'] : null,
            'console' => (string) ($config['console'] ?? 'bin/neo'),
            'php_binary' => $binary === '' ? null : $binary,
            'tasks' => array_values(array_filter((array) ($config['tasks'] ?? []), 'is_array')),
            'providers' => array_values(array_map('strval', (array) ($config['providers'] ?? []))),
            'history_max' => max(10, (int) ($config['history']['max'] ?? 500)),
            'root_path' => $root,
            'storage' => $storage,
            'history_file' => $storage . DIRECTORY_SEPARATOR . 'history.jsonl',
            'heartbeat_file' => $storage . DIRECTORY_SEPARATOR . 'heartbeat',
            'lock_path' => $storage . DIRECTORY_SEPARATOR . 'locks',
        ];
    }

    public static function discover(ContainerManagerInterface $container): array
    {
        $paths = ModuleSources::paths($container);
        $resources = ModuleSources::resources($container);
        $builder = static function () use ($paths, $resources): array {
            $discovery = new TaskDiscovery($paths);

            return [$discovery->discover(), $discovery->getResources() + $resources];
        };

        if (!$container->has('kernel.cache_path')) {
            return $builder()[0];
        }

        $environment = $container->has('kernel.environment') ? (string) $container->get('kernel.environment') : 'dev';
        $debug = $container->has('kernel.debug') && (bool) $container->get('kernel.debug');
        $file = (string) $container->get('kernel.cache_path') . DIRECTORY_SEPARATOR . self::CACHE_DIRECTORY . DIRECTORY_SEPARATOR . 'tasks.' . $environment . '.php';

        return (new ResourceCache($file, $debug))->load($builder);
    }
}