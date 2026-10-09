<?php

declare(strict_types=1);

namespace NeoPHP\Component\Routing\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Routing\Cache\RouteCache;
use NeoPHP\Component\Routing\Loader\YamlRouteLoader;
use NeoPHP\Component\Routing\RoutingManager;
use NeoPHP\Component\Routing\RoutingManagerInterface;
use NeoPHP\Package\Yaml\YamlManagerInterface;

/**
 * @internal
 */
class RoutingProvider extends AbstractProvider
{
    public const ROUTE_FILES = ['routes.yaml', 'routes.yml'];

    public const CACHE_DIRECTORY = 'routing';

    public const URL_KEY = 'framework.app.url';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(RoutingManagerInterface::class, static function (ContainerManagerInterface $container): RoutingManagerInterface {
            $resolver = null;

            if ($container->has(ConfigManagerInterface::class)) {
                $config = $container->get(ConfigManagerInterface::class);
                $resolver = static fn (array $definitions): array => $config->resolve($definitions);
            }

            $yaml = $container->get(YamlManagerInterface::class);
            $routing = new RoutingManager($yaml, null, $resolver);
            $routing->setBaseUrl(static fn (): ?string => self::baseUrl($container));
            $routing->setBasePath(static fn (): string => self::basePath($container));
            $file = self::routesFile($container);

            if ($file === null) {
                return $routing;
            }

            if (!$container->has('kernel.cache_path')) {
                return $routing->loadYaml($file);
            }

            $environment = $container->has('kernel.environment') ? (string) $container->get('kernel.environment') : 'dev';
            $debug = $container->has('kernel.debug') && (bool) $container->get('kernel.debug');
            $rootPath = $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : dirname($file, 2);
            $cacheFile = (string) $container->get('kernel.cache_path') . DIRECTORY_SEPARATOR . self::CACHE_DIRECTORY . DIRECTORY_SEPARATOR . 'routes.' . $environment . '.php';

            $routes = (new RouteCache($cacheFile, $debug))->load(static function () use ($yaml, $resolver, $file, $rootPath): array {
                $loader = new YamlRouteLoader($yaml, $resolver);
                $routes = $loader->load($file);
                $resources = $loader->getResources();

                $files = [...(glob($rootPath . DIRECTORY_SEPARATOR . '.env*') ?: []), $rootPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.json'];

                foreach ($files as $path) {
                    if (is_file($path)) {
                        $resources[$path] = (int) filemtime($path);
                    }
                }

                return [$routes, $resources];
            });

            $routing->getRoutes()->addCollection($routes);

            return $routing;
        });

        $container->alias(RoutingManager::class, RoutingManagerInterface::class);
    }

    protected static function basePath(ContainerManagerInterface $container): string
    {
        $request = $container->has(Request::class) ? $container->get(Request::class) : null;

        return $request instanceof Request ? $request->getBasePath() : '';
    }

    protected static function baseUrl(ContainerManagerInterface $container): ?string
    {
        if ($container->has(Request::class)) {
            $request = $container->get(Request::class);

            if ($request instanceof Request && (string) $request->headers->get('Host', '') !== '') {
                return $request->getSchemeAndHttpHost();
            }
        }

        if (!$container->has(ConfigManagerInterface::class)) {
            return null;
        }

        $url = $container->get(ConfigManagerInterface::class)->get(self::URL_KEY);

        return is_string($url) && $url !== '' ? $url : null;
    }

    protected static function routesFile(ContainerManagerInterface $container): ?string
    {
        $configPath = $container->has('kernel.config_path') ? (string) $container->get('kernel.config_path') : '';

        if ($configPath === '') {
            return null;
        }

        foreach (self::ROUTE_FILES as $file) {
            $path = $configPath . DIRECTORY_SEPARATOR . $file;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}