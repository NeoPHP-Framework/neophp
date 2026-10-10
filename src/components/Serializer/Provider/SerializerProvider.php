<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Controller\Contract\ArgumentResolverInterface;
use NeoPHP\Component\Kernel\Cache\ResourceCache;
use NeoPHP\Component\Kernel\Module\ModuleSources;
use NeoPHP\Component\Serializer\ArgumentResolver\RequestPayloadResolver;
use NeoPHP\Component\Serializer\Discovery\NormalizerDiscovery;
use NeoPHP\Component\Serializer\Exception\SerializerException;
use NeoPHP\Component\Serializer\SerializerManager;
use NeoPHP\Component\Serializer\SerializerManagerInterface;
use NeoPHP\Package\Orm\OrmManagerInterface;
use NeoPHP\Package\Yaml\YamlManagerInterface;

/**
 * @internal
 */
class SerializerProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.serializer';

    public const CACHE_DIRECTORY = 'serializer';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(SerializerManagerInterface::class, static function (ContainerManagerInterface $container): SerializerManagerInterface {
            $config = self::config($container);
            $orm = $container->has(OrmManagerInterface::class) ? static fn (): OrmManagerInterface => $container->get(OrmManagerInterface::class) : null;
            $yaml = $container->has(YamlManagerInterface::class) ? $container->get(YamlManagerInterface::class) : null;
            $serializer = SerializerManager::create($config, $orm, $yaml);

            foreach (self::normalizers($container, $config) as $class => $priority) {
                $serializer->addNormalizer($container->get((string) $class), (int) $priority);
            }

            return $serializer;
        });

        $container->alias(SerializerManager::class, SerializerManagerInterface::class);
        $container->alias('serializer', SerializerManagerInterface::class);
        $container->singleton(RequestPayloadResolver::class, static fn (ContainerManagerInterface $container): RequestPayloadResolver => new RequestPayloadResolver($container));

        $resolvers = $container->has(ArgumentResolverInterface::SERVICES_ID) ? (array) $container->get(ArgumentResolverInterface::SERVICES_ID) : [];
        $container->instance(ArgumentResolverInterface::SERVICES_ID, [...$resolvers, RequestPayloadResolver::class]);
    }

    public static function config(ContainerManagerInterface $container): array
    {
        return $container->has(ConfigManagerInterface::class) ? (array) $container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) : [];
    }

    public static function normalizers(ContainerManagerInterface $container, array $config): array
    {
        $normalizers = self::discover($container);

        foreach ((array) ($config['normalizers'] ?? []) as $normalizer) {
            $normalizer = is_array($normalizer) ? $normalizer : ['class' => $normalizer];
            $class = (string) ($normalizer['class'] ?? '');

            if (!class_exists($class)) {
                throw new SerializerException('The normalizer "{class}" of config/framework/serializer.yaml does not exist.', 0, null, ['class' => $class]);
            }

            $normalizers[$class] = (int) ($normalizer['priority'] ?? $normalizers[$class] ?? 0);
        }

        return $normalizers;
    }

    protected static function discover(ContainerManagerInterface $container): array
    {
        if (!$container->has('kernel.root_path')) {
            return [];
        }

        $paths = ModuleSources::paths($container);
        $resources = ModuleSources::resources($container);
        $builder = static function () use ($paths, $resources): array {
            $discovery = new NormalizerDiscovery($paths);

            return [$discovery->discover(), $discovery->getResources() + $resources];
        };

        if (!$container->has('kernel.cache_path')) {
            return $builder()[0];
        }

        $environment = $container->has('kernel.environment') ? (string) $container->get('kernel.environment') : 'dev';
        $debug = $container->has('kernel.debug') && (bool) $container->get('kernel.debug');
        $file = (string) $container->get('kernel.cache_path') . DIRECTORY_SEPARATOR . self::CACHE_DIRECTORY . DIRECTORY_SEPARATOR . 'normalizers.' . $environment . '.php';

        return (new ResourceCache($file, $debug))->load($builder);
    }
}