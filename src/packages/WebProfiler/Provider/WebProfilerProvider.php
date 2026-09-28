<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Provider;

use NeoPHP\Component\Config\Contract\ConfigInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Kernel\Cache\ResourceCache;
use NeoPHP\Package\WebProfiler\Contract\BlockRendererInterface;
use NeoPHP\Package\WebProfiler\Contract\ProfileStorageInterface;
use NeoPHP\Package\WebProfiler\Discovery\ProfilerDiscovery;
use NeoPHP\Package\WebProfiler\Exception\WebProfilerException;
use NeoPHP\Package\WebProfiler\Profiler;
use NeoPHP\Package\WebProfiler\Renderer\BlockRenderer;
use NeoPHP\Package\WebProfiler\Renderer\TemplateRenderer;
use NeoPHP\Package\WebProfiler\Renderer\ToolbarInjector;
use NeoPHP\Package\WebProfiler\Stopwatch\Stopwatch;
use NeoPHP\Package\WebProfiler\Storage\FileProfileStorage;

class WebProfilerProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'packages.web_profiler';

    public const CONFIG_ID = 'web_profiler.config';

    public const CACHE_DIRECTORY = 'web_profiler';

    public const FRAMEWORK_SOURCES = ['components', 'packages', 'process'];

    public function register(ContainerInterface $container): void
    {
        $container->singleton(self::CONFIG_ID, static fn (ContainerInterface $container): array => self::configure($container));

        $container->singleton(Stopwatch::class, static fn (): Stopwatch => new Stopwatch());
        $container->alias('stopwatch', Stopwatch::class);

        $container->singleton(ProfileStorageInterface::class, static function (ContainerInterface $container): ProfileStorageInterface {
            $config = $container->get(self::CONFIG_ID);

            return new FileProfileStorage((string) $config['storage'], (int) $config['max_profiles'], (int) $config['lifetime']);
        });
        $container->alias(FileProfileStorage::class, ProfileStorageInterface::class);

        $container->singleton(BlockRenderer::class, static function (ContainerInterface $container): BlockRenderer {
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

        $container->singleton(TemplateRenderer::class, static fn (ContainerInterface $container): TemplateRenderer => new TemplateRenderer($container->get(BlockRenderer::class)));

        $container->singleton(Profiler::class, static fn (ContainerInterface $container): Profiler => new Profiler(
            $container,
            $container->get(ProfileStorageInterface::class),
            $container->get(Stopwatch::class),
            $container->get(self::CONFIG_ID),
            $container->get(self::CONFIG_ID)['enabled'] ? self::discover($container) : [],
        ));
        $container->alias('profiler', Profiler::class);

        $container->singleton(ToolbarInjector::class, static fn (ContainerInterface $container): ToolbarInjector => new ToolbarInjector(
            $container->get(Profiler::class),
            $container->get(TemplateRenderer::class),
        ));
    }

    public static function configure(ContainerInterface $container): array
    {
        $config = $container->has(ConfigInterface::class) ? (array) ($container->get(ConfigInterface::class)->get(self::CONFIG_KEY, []) ?? []) : [];
        $config = [...Profiler::DEFAULTS, ...array_filter($config, static fn (mixed $value): bool => $value !== null)];
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

    public static function discover(ContainerInterface $container): array
    {
        $frameworkPath = dirname(__DIR__, 3);
        $sources = array_map(static fn (string $directory): string => $frameworkPath . DIRECTORY_SEPARATOR . $directory, self::FRAMEWORK_SOURCES);
        $applications = $container->has('kernel.root_path') ? [(string) $container->get('kernel.root_path') . DIRECTORY_SEPARATOR . 'src'] : [];

        $builder = static function () use ($sources, $applications): array {
            $discovery = new ProfilerDiscovery($sources, $applications);

            return [$discovery->discover(), $discovery->getResources()];
        };

        if (!$container->has('kernel.cache_path')) {
            return $builder()[0];
        }

        $environment = $container->has('kernel.environment') ? (string) $container->get('kernel.environment') : 'dev';
        $debug = $container->has('kernel.debug') && (bool) $container->get('kernel.debug');
        $file = (string) $container->get('kernel.cache_path') . DIRECTORY_SEPARATOR . self::CACHE_DIRECTORY . DIRECTORY_SEPARATOR . 'profilers.' . $environment . '.php';

        return (new ResourceCache($file, $debug))->load($builder);
    }

    protected static function absolute(string $path, string $root): string
    {
        return preg_match('#^([a-zA-Z]:)?[/\\\\]#', $path) === 1 ? $path : $root . DIRECTORY_SEPARATOR . ltrim($path, './\\');
    }
}