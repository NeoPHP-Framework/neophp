<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Provider;

use NeoPHP\Component\Config\Contract\ConfigInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Controller\Contract\ArgumentResolverInterface;
use NeoPHP\Component\Kernel\Cache\ResourceCache;
use NeoPHP\Component\Serializer\ArgumentResolver\RequestPayloadResolver;
use NeoPHP\Component\Serializer\Contract\SerializerInterface;
use NeoPHP\Component\Serializer\Discovery\NormalizerDiscovery;
use NeoPHP\Component\Serializer\Exception\SerializerException;
use NeoPHP\Component\Serializer\SerializerManager;
use NeoPHP\Package\Orm\Contract\OrmInterface;
use NeoPHP\Package\Yaml\Contract\YamlInterface;

class SerializerProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.serializer';

    public const CACHE_DIRECTORY = 'serializer';

    public function register(ContainerInterface $container): void
    {
        $container->singleton(SerializerInterface::class, static function (ContainerInterface $container): SerializerInterface {
            $config = self::config($container);
            $orm = $container->has(OrmInterface::class) ? static fn (): OrmInterface => $container->get(OrmInterface::class) : null;
            $yaml = $container->has(YamlInterface::class) ? $container->get(YamlInterface::class) : null;
            $serializer = SerializerManager::create($config, $orm, $yaml);

            foreach (self::normalizers($container, $config) as $class => $priority) {
                $serializer->addNormalizer($container->get((string) $class), (int) $priority);
            }

            return $serializer;
        });

        $container->alias(SerializerManager::class, SerializerInterface::class);
        $container->alias('serializer', SerializerInterface::class);
        $container->singleton(RequestPayloadResolver::class, static fn (ContainerInterface $container): RequestPayloadResolver => new RequestPayloadResolver($container));

        $resolvers = $container->has(ArgumentResolverInterface::SERVICES_ID) ? (array) $container->get(ArgumentResolverInterface::SERVICES_ID) : [];
        $container->instance(ArgumentResolverInterface::SERVICES_ID, [...$resolvers, RequestPayloadResolver::class]);
    }

    public static function config(ContainerInterface $container): array
    {
        return $container->has(ConfigInterface::class) ? (array) $container->get(ConfigInterface::class)->get(self::CONFIG_KEY, []) : [];
    }

    public static function normalizers(ContainerInterface $container, array $config): array
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

    protected static function discover(ContainerInterface $container): array
    {
        if (!$container->has('kernel.root_path')) {
            return [];
        }

        $paths = [(string) $container->get('kernel.root_path') . DIRECTORY_SEPARATOR . 'src'];
        $builder = static function () use ($paths): array {
            $discovery = new NormalizerDiscovery($paths);

            return [$discovery->discover(), $discovery->getResources()];
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