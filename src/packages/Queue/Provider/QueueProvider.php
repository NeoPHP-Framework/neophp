<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Database\DatabaseManagerInterface;
use NeoPHP\Component\Event\EventManagerInterface;
use NeoPHP\Component\Kernel\Cache\ResourceCache;
use NeoPHP\Component\Kernel\Module\ModuleSources;
use NeoPHP\Package\Queue\Contract\MessageBusInterface;
use NeoPHP\Package\Queue\Contract\TransportFactoryInterface;
use NeoPHP\Package\Queue\Discovery\HandlerDiscovery;
use NeoPHP\Package\Queue\Exception\ConfigurationException;
use NeoPHP\Package\Queue\Handler\HandlerInvoker;
use NeoPHP\Package\Queue\Handler\HandlerLocator;
use NeoPHP\Package\Queue\Maker\MessageMaker;
use NeoPHP\Package\Queue\QueueManager;
use NeoPHP\Package\Queue\QueueManagerInterface;
use NeoPHP\Package\Queue\Retry\RetryStrategy;
use NeoPHP\Package\Queue\Serializer\MessageSerializer;
use NeoPHP\Package\Queue\Trace\QueueTrace;
use NeoPHP\Package\Queue\Transport\TransportFactory;
use NeoPHP\Package\Queue\Worker\RestartSignal;
use NeoPHP\Package\Queue\Worker\Worker;

/**
 * @internal
 */
class QueueProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'packages.queue';

    public const CONFIG_ID = 'queue.config';

    public const SECRET_KEY = 'framework.app.secret';

    public const DATABASE_CONFIG_KEY = 'framework.database';

    public const PROFILER_CONFIG_ID = 'web_profiler.config';

    public const CACHE_DIRECTORY = 'queue';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(self::CONFIG_ID, static fn (ContainerManagerInterface $container): array => self::configure($container));

        $container->singleton(HandlerLocator::class, static function (ContainerManagerInterface $container): HandlerLocator {
            $config = $container->get(self::CONFIG_ID);

            return new HandlerLocator(array_merge_recursive(self::discover($container), (array) $config['handlers']));
        });

        $container->singleton(HandlerInvoker::class, static fn (ContainerManagerInterface $container): HandlerInvoker => new HandlerInvoker($container->get(HandlerLocator::class), $container));

        $container->singleton(MessageSerializer::class, static fn (ContainerManagerInterface $container): MessageSerializer => new MessageSerializer($container->get(self::CONFIG_ID)['secret']));

        $container->singleton(RetryStrategy::class, static fn (ContainerManagerInterface $container): RetryStrategy => RetryStrategy::fromConfig((array) $container->get(self::CONFIG_ID)['retry']));

        $container->singleton(TransportFactory::class, static function (ContainerManagerInterface $container): TransportFactory {
            $config = $container->get(self::CONFIG_ID);
            $factories = [];

            foreach ((array) $config['transports_factories'] as $class) {
                $factory = $container->get((string) $class);

                if (!$factory instanceof TransportFactoryInterface) {
                    throw new ConfigurationException('The queue transport factory "{class}" must implement {interface}.', 0, null, ['class' => $class, 'interface' => TransportFactoryInterface::class]);
                }

                $factories[] = $factory;
            }

            return new TransportFactory(
                $container->get(MessageSerializer::class),
                $container->get(HandlerInvoker::class),
                $container->has(DatabaseManagerInterface::class) ? $container->get(DatabaseManagerInterface::class) : null,
                (string) $config['root_path'],
                ['auto_setup' => $config['auto_setup'], 'retry_after' => $config['retry_after']],
                $factories,
            );
        });

        $container->singleton(QueueManagerInterface::class, static function (ContainerManagerInterface $container): QueueManagerInterface {
            $config = $container->get(self::CONFIG_ID);

            return new QueueManager(
                (array) $config['transports'],
                $container->get(TransportFactory::class),
                $container->get(HandlerInvoker::class),
                $container->get(RetryStrategy::class),
                (string) $config['default_transport'],
                (array) $config['routing'],
                $container->has(EventManagerInterface::class) ? $container->get(EventManagerInterface::class) : null,
                self::profilingEnabled($container) ? $container->get(QueueTrace::class) : null,
            );
        });

        $container->singleton(QueueTrace::class, static fn (): QueueTrace => new QueueTrace());

        $container->singleton(RestartSignal::class, static fn (ContainerManagerInterface $container): RestartSignal => new RestartSignal((string) $container->get(self::CONFIG_ID)['restart_file']));

        $container->bind(Worker::class, static fn (ContainerManagerInterface $container): Worker => new Worker(
            $container->get(QueueManagerInterface::class),
            $container->get(RetryStrategy::class),
            $container->get(RestartSignal::class),
            $container->has(EventManagerInterface::class) ? $container->get(EventManagerInterface::class) : null,
        ));

        $container->singleton(MessageMaker::class, static function (ContainerManagerInterface $container): MessageMaker {
            $root = (string) $container->get(self::CONFIG_ID)['root_path'];

            return new MessageMaker($root . DIRECTORY_SEPARATOR . 'src');
        });

        $container->alias(MessageBusInterface::class, QueueManagerInterface::class);
        $container->alias(QueueManager::class, QueueManagerInterface::class);
        $container->alias('queue', QueueManagerInterface::class);
        $container->alias('message_bus', QueueManagerInterface::class);
    }

    public static function configure(ContainerManagerInterface $container): array
    {
        $settings = $container->has(ConfigManagerInterface::class) ? $container->get(ConfigManagerInterface::class) : null;
        $config = $settings instanceof ConfigManagerInterface ? (array) ($settings->get(self::CONFIG_KEY, []) ?? []) : [];
        $root = $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : (string) getcwd();
        $secret = $settings instanceof ConfigManagerInterface ? $settings->get(self::SECRET_KEY) : null;
        $secret ??= $_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? null;
        $transports = [];

        foreach ((array) ($config['transports'] ?? []) as $name => $transport) {
            $transport = is_array($transport) ? $transport : ['dsn' => (string) $transport];
            $transport['dsn'] = self::resolveKernelParameters($container, (string) ($transport['dsn'] ?? ''));
            $transport['options'] = (array) ($transport['options'] ?? []);
            $transports[(string) $name] = $transport;
        }

        if ($transports === []) {
            $transports['async'] = ['dsn' => self::defaultDsn($container, $settings), 'options' => []];
        }

        $transports['sync'] ??= ['dsn' => 'sync://', 'options' => []];
        $default = (string) ($config['default_transport'] ?? array_key_first($transports));
        $handlers = [];

        foreach ((array) ($config['handlers'] ?? []) as $message => $list) {
            foreach (is_array($list) && !isset($list['handler']) ? $list : [$list] as $handler) {
                $handler = is_array($handler) ? $handler : ['handler' => (string) $handler];
                $handlers[(string) $message][] = [(string) ($handler['handler'] ?? ''), (string) ($handler['method'] ?? '__invoke'), (int) ($handler['priority'] ?? 0)];
            }
        }

        return [
            'default_transport' => $default,
            'transports' => $transports,
            'routing' => array_map('strval', (array) ($config['routing'] ?? [])),
            'handlers' => $handlers,
            'retry' => [
                'max_attempts' => (int) ($config['retry']['max_attempts'] ?? 3),
                'delay' => (float) ($config['retry']['delay'] ?? 1),
                'multiplier' => (float) ($config['retry']['multiplier'] ?? 2),
                'max_delay' => (float) ($config['retry']['max_delay'] ?? 3600),
            ],
            'auto_setup' => filter_var($config['auto_setup'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'retry_after' => (int) ($config['retry_after'] ?? 90),
            'transports_factories' => array_values(array_map('strval', (array) ($config['transports_factories'] ?? []))),
            'profiler_stats' => filter_var($config['profiler_stats'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'secret' => $secret === null || $secret === '' ? null : (string) $secret,
            'root_path' => $root,
            'restart_file' => $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'queue' . DIRECTORY_SEPARATOR . 'restart',
        ];
    }

    public static function discover(ContainerManagerInterface $container): array
    {
        $paths = ModuleSources::paths($container);
        $resources = ModuleSources::resources($container);
        $builder = static function () use ($paths, $resources): array {
            $discovery = new HandlerDiscovery($paths);

            return [$discovery->discover(), $discovery->getResources() + $resources];
        };

        if (!$container->has('kernel.cache_path')) {
            return $builder()[0];
        }

        $environment = $container->has('kernel.environment') ? (string) $container->get('kernel.environment') : 'dev';
        $debug = $container->has('kernel.debug') && (bool) $container->get('kernel.debug');
        $file = (string) $container->get('kernel.cache_path') . DIRECTORY_SEPARATOR . self::CACHE_DIRECTORY . DIRECTORY_SEPARATOR . 'handlers.' . $environment . '.php';

        return (new ResourceCache($file, $debug))->load($builder);
    }

    public static function profilingEnabled(ContainerManagerInterface $container): bool
    {
        if (!$container->bound(self::PROFILER_CONFIG_ID) && !$container->has(self::PROFILER_CONFIG_ID)) {
            return false;
        }

        $config = $container->get(self::PROFILER_CONFIG_ID);

        return is_array($config) && (bool) ($config['enabled'] ?? false);
    }

    protected static function defaultDsn(ContainerManagerInterface $container, ?ConfigManagerInterface $settings): string
    {
        $env = $_SERVER['QUEUE_DSN'] ?? $_ENV['QUEUE_DSN'] ?? getenv('QUEUE_DSN');

        if (is_string($env) && $env !== '') {
            return self::resolveKernelParameters($container, $env);
        }

        $database = $settings instanceof ConfigManagerInterface ? (array) ($settings->get(self::DATABASE_CONFIG_KEY, []) ?? []) : [];

        if ($container->has(DatabaseManagerInterface::class) && (array) ($database['connections'] ?? []) !== []) {
            return 'database://default';
        }

        return 'filesystem://var/queue';
    }

    protected static function resolveKernelParameters(ContainerManagerInterface $container, string $value): string
    {
        return (string) preg_replace_callback('/%(kernel\.\w+)%/', static fn (array $m): string => $container->has($m[1]) ? (string) $container->get($m[1]) : $m[0], $value);
    }
}