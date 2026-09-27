<?php

declare(strict_types=1);

namespace NeoPHP\Component\Database\Provider;

use NeoPHP\Component\Config\Contract\ConfigInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Database\Connection\Connection;
use NeoPHP\Component\Database\Contract\ConnectionInterface;
use NeoPHP\Component\Database\Contract\DatabaseInterface;
use NeoPHP\Component\Database\Contract\QueryLoggerInterface;
use NeoPHP\Component\Database\DatabaseManager;
use NeoPHP\Component\Database\Logger\QueryLogger;

class DatabaseProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.database';

    public const CONNECTION_PREFIX = 'database.connection.';

    public const PROFILER_CONFIG_ID = 'web_profiler.config';

    public const STOPWATCH_ID = 'stopwatch';

    public const STOPWATCH_CATEGORY = 'database';

    public const STOPWATCH_SQL_LENGTH = 60;

    public function register(ContainerInterface $container): void
    {
        $container->singleton(DatabaseInterface::class, static function (ContainerInterface $container): DatabaseInterface {
            $config = self::config($container);
            $connections = [];

            foreach ((array) ($config['connections'] ?? []) as $name => $connection) {
                $connections[(string) $name] = self::resolveKernelParameters($container, is_string($connection) ? ['url' => $connection] : (array) $connection);
            }

            $default = isset($config['default']) && $config['default'] !== '' ? (string) $config['default'] : null;
            $rootPath = $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : (string) getcwd();

            $manager = new DatabaseManager($connections, $default, $rootPath);

            return $manager->setQueryLoggerResolver(static fn (): ?QueryLoggerInterface => self::profilingEnabled($container) ? $container->get(QueryLoggerInterface::class) : null);
        });

        $container->singleton(QueryLoggerInterface::class, static function (ContainerInterface $container): QueryLoggerInterface {
            $logger = new QueryLogger();

            if ($container->bound(self::STOPWATCH_ID) || $container->has(self::STOPWATCH_ID)) {
                $stopwatch = $container->get(self::STOPWATCH_ID);
                $logger->setListener(static function (array $query) use ($stopwatch): void {
                    $name = sprintf('%s #%d %s', $query['connection'], $query['index'], mb_substr((string) preg_replace('/\s+/', ' ', $query['sql']), 0, self::STOPWATCH_SQL_LENGTH));
                    $stopwatch->start($name, self::STOPWATCH_CATEGORY, $query['start']);
                    $stopwatch->stop($name, $query['start'] + $query['duration'] / 1000);
                });
            }

            return $logger;
        });
        $container->alias(QueryLogger::class, QueryLoggerInterface::class);
        $container->alias('database.query_logger', QueryLoggerInterface::class);

        $container->singleton(ConnectionInterface::class, static fn (ContainerInterface $container): ConnectionInterface => $container->get(DatabaseInterface::class)->connection());

        $container->alias(DatabaseManager::class, DatabaseInterface::class);
        $container->alias(Connection::class, ConnectionInterface::class);
        $container->alias('database', DatabaseInterface::class);
        $container->alias('database.connection', ConnectionInterface::class);
    }

    public function boot(ContainerInterface $container): void
    {
        foreach (array_keys((array) (self::config($container)['connections'] ?? [])) as $name) {
            $name = (string) $name;
            $container->singleton(self::CONNECTION_PREFIX . $name, static fn (ContainerInterface $container): ConnectionInterface => $container->get(DatabaseInterface::class)->connection($name));
        }
    }

    protected static function config(ContainerInterface $container): array
    {
        return $container->has(ConfigInterface::class) ? (array) $container->get(ConfigInterface::class)->get(self::CONFIG_KEY, []) : [];
    }

    protected static function profilingEnabled(ContainerInterface $container): bool
    {
        if (!$container->bound(self::PROFILER_CONFIG_ID) && !$container->has(self::PROFILER_CONFIG_ID)) {
            return false;
        }

        $config = $container->get(self::PROFILER_CONFIG_ID);

        return is_array($config) && (bool) ($config['enabled'] ?? false);
    }

    protected static function resolveKernelParameters(ContainerInterface $container, array $params): array
    {
        foreach (['url', 'path'] as $key) {
            if (!isset($params[$key]) || !is_string($params[$key])) {
                continue;
            }

            $params[$key] = (string) preg_replace_callback('/%(kernel\.\w+)%/', static fn (array $m): string => $container->has($m[1]) ? (string) $container->get($m[1]) : $m[0], $params[$key]);
        }

        return $params;
    }
}