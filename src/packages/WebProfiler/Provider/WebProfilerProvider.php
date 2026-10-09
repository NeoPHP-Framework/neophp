<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Kernel\Cache\ResourceCache;
use NeoPHP\Component\Kernel\KernelManagerInterface;
use NeoPHP\Package\WebProfiler\Contract\BlockRendererInterface;
use NeoPHP\Package\WebProfiler\Contract\ProfileStorageInterface;
use NeoPHP\Package\WebProfiler\Discovery\ProfilerDiscovery;
use NeoPHP\Package\WebProfiler\Exception\WebProfilerException;
use NeoPHP\Package\WebProfiler\Renderer\BlockRenderer;
use NeoPHP\Package\WebProfiler\Renderer\TemplateRenderer;
use NeoPHP\Package\WebProfiler\Renderer\ToolbarInjector;
use NeoPHP\Package\WebProfiler\Stopwatch\Stopwatch;
use NeoPHP\Package\WebProfiler\Storage\FileProfileStorage;
use NeoPHP\Package\WebProfiler\WebProfilerManager;
use NeoPHP\Package\WebProfiler\WebProfilerManagerInterface;

/**
 * @internal
 */
class WebProfilerProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'packages.web_profiler';

    public const CONFIG_ID = 'web_profiler.config';

    public const CACHE_DIRECTORY = 'web_profiler';

    public const FRAMEWORK_SOURCES = ['components', 'packages', 'process'];

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(self::CONFIG_ID, static fn (ContainerManagerInterface $container): array => self::configure($container));

        $container->singleton(Stopwatch::class, static fn (): Stopwatch => new Stopwatch());
        $container->alias('stopwatch', Stopwatch::class);

        $container->singleton(ProfileStorageInterface::class, static function (ContainerManagerInterface $container): ProfileStorageInterface {
            $config = $container->get(self::CONFIG_ID);

            return new FileProfileStorage((string) $config['storage'], (int) $config['max_profiles'], (int) $config['lifetime']);
        });
        $container->alias(FileProfileStorage::class, ProfileStorageInterface::class);

        $container->singleton(BlockRenderer::class, static function (ContainerManagerInterface $container): BlockRenderer {
            $renderers = [];

            foreach ((array) $container->get(self::CONFIG_ID)['block_renderers'] as $class) {
                $renderer = $container->get((string) $class);

                if (!$renderer instanceof BlockRendererInterface) {
                    throw new WebProfilerException('The block renderer "{class}" must implement {interface}.', 0, null, [
                        'class' => $class,
                        'interface' => BlockRendererInterface::class,
                    ]);
                }

                $renderers[] = $renderer;
            }

            return new BlockRenderer($renderers);
        });

        $container->singleton(TemplateRenderer::class, static fn (ContainerManagerInterface $container): TemplateRenderer => new TemplateRenderer($container->get(BlockRenderer::class)));

        $container->singleton(WebProfilerManagerInterface::class, static fn (ContainerManagerInterface $container): WebProfilerManagerInterface => new WebProfilerManager(
            $container,
            $container->get(ProfileStorageInterface::class),
            $container->get(Stopwatch::class),
            $container->get(self::CONFIG_ID),
            $container->get(self::CONFIG_ID)['enabled'] ? self::discover($container) : [],
        ));
        $container->alias(WebProfilerManager::class, WebProfilerManagerInterface::class);
        $container->alias('profiler', WebProfilerManagerInterface::class);

        $container->singleton(ToolbarInjector::class, static fn (ContainerManagerInterface $container): ToolbarInjector => new ToolbarInjector(
            $container->get(WebProfilerManagerInterface::class),
            $container->get(TemplateRenderer::class),
        ));
    }

    public static function configure(ContainerManagerInterface $container): array
    {
        $config = $container->has(ConfigManagerInterface::class) ? (array) ($container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) ?? []) : [];
        $config = [...WebProfilerManager::DEFAULTS, ...array_filter($config, static fn (mixed $value): bool => $value !== null)];
        $debug = $container->has('kernel.debug') && (bool) $container->get('kernel.debug');
        $root = $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : (string) getcwd();
        $storage = (string) ($config['storage'] ?? '');

        $config['enabled'] = $debug && filter_var($config['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $config['toolbar'] = filter_var($config['toolbar'], FILTER_VALIDATE_BOOLEAN);
        $config['storage'] = $storage === '' ? $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'profiler' : self::absolute($storage, $root);
        $config['excluded_paths'] = array_values((array) $config['excluded_paths']);
        $config['panels'] = array_values((array) $config['panels']);
        $config['block_renderers'] = array_values((array) $config['block_renderers']);
        $config['allowed_ips'] = is_string($config['allowed_ips']) ? array_values(array_filter(array_map('trim', explode(',', $config['allowed_ips'])))) : array_values((array) $config['allowed_ips']);

        return $config;
    }

    public static function discover(ContainerManagerInterface $container): array
    {
        $frameworkPath = dirname(__DIR__, 3);
        $sources = array_map(static fn (string $directory): string => $frameworkPath . DIRECTORY_SEPARATOR . $directory, self::FRAMEWORK_SOURCES);
        $applications = $container->has('kernel.root_path') ? [(string) $container->get('kernel.root_path') . DIRECTORY_SEPARATOR . 'src'] : [];

        $builder = static function () use ($sources, $applications): array {
            $discovery = new ProfilerDiscovery($sources, $applications);

            return [$discovery->discover(), $discovery->getResources()];
        };

        if (!$container->has('kernel.cache_path')) {
            return self::enabled($container, $builder()[0]);
        }

        $environment = $container->has('kernel.environment') ? (string) $container->get('kernel.environment') : 'dev';
        $debug = $container->has('kernel.debug') && (bool) $container->get('kernel.debug');
        $file = (string) $container->get('kernel.cache_path') . DIRECTORY_SEPARATOR . self::CACHE_DIRECTORY . DIRECTORY_SEPARATOR . 'profilers.' . $environment . '.php';

        return self::enabled($container, (new ResourceCache($file, $debug))->load($builder));
    }

    protected static function enabled(ContainerManagerInterface $container, array $elements): array
    {
        if (!$container->bound(KernelManagerInterface::class)) {
            return $elements;
        }

        $kernel = $container->get(KernelManagerInterface::class);

        return array_filter($elements, static fn (string $class): bool => $kernel->isEnabled($class), ARRAY_FILTER_USE_KEY);
    }

    protected static function absolute(string $path, string $root): string
    {
        return preg_match('#^([a-zA-Z]:)?[/\\\\]#', $path) === 1 ? $path : $root . DIRECTORY_SEPARATOR . ltrim($path, './\\');
    }
}