<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Provider;

use NeoPHP\Component\Api\ArgumentResolver\PageRequestResolver;
use NeoPHP\Component\Api\Cors\CorsManager;
use NeoPHP\Component\Api\OpenApi\OpenApiGenerator;
use NeoPHP\Component\Api\Pagination\Contract\PaginatorInterface;
use NeoPHP\Component\Api\Pagination\Paginator;
use NeoPHP\Component\Api\ProblemDetails\ProblemDetailsFactory;
use NeoPHP\Component\Api\RateLimiter\RateLimiterFactory;
use NeoPHP\Component\Api\RateLimiter\RequestKeyResolver;
use NeoPHP\Component\Cache\Adapter\ArrayAdapter;
use NeoPHP\Component\Cache\CachePool;
use NeoPHP\Component\Cache\Contract\CacheInterface;
use NeoPHP\Component\Cache\Contract\CacheManagerInterface;
use NeoPHP\Component\Config\Contract\ConfigInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Controller\Contract\ArgumentResolverInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Routing\Contract\RoutingInterface;
use NeoPHP\Component\Serializer\Contract\SerializerInterface;
use NeoPHP\Component\Serializer\Mapping\MetadataFactory;
use NeoPHP\Component\Serializer\SerializerManager;

class ApiProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.api';

    public const CONFIG_ID = 'api.config';

    public const DEFAULT_CONFIG = [
        'cors' => ['enabled' => false, 'defaults' => CorsManager::DEFAULTS, 'paths' => []],
        'rate_limiter' => ['cache_pool' => null, 'headers' => true, 'limiters' => []],
        'pagination' => Paginator::DEFAULTS,
        'problem_details' => ProblemDetailsFactory::DEFAULTS,
        'openapi' => OpenApiGenerator::DEFAULTS,
    ];

    public function register(ContainerInterface $container): void
    {
        $container->singleton(self::CONFIG_ID, static fn (ContainerInterface $container): array => self::config($container));

        $container->singleton(CorsManager::class, static fn (ContainerInterface $container): CorsManager => new CorsManager($container->get(self::CONFIG_ID)['cors']));

        $container->singleton(RateLimiterFactory::class, static function (ContainerInterface $container): RateLimiterFactory {
            $config = $container->get(self::CONFIG_ID)['rate_limiter'];

            return new RateLimiterFactory((array) $config['limiters'], static fn (): CacheInterface => self::storage($container, $config['cache_pool']));
        });

        $container->singleton(RequestKeyResolver::class, static fn (ContainerInterface $container): RequestKeyResolver => new RequestKeyResolver($container));

        $container->singleton(PaginatorInterface::class, static fn (ContainerInterface $container): PaginatorInterface => new Paginator(
            $container->get(self::CONFIG_ID)['pagination'],
            static fn (): ?Request => $container->has(Request::class) ? $container->get(Request::class) : null,
        ));
        $container->alias(Paginator::class, PaginatorInterface::class);

        $container->singleton(ProblemDetailsFactory::class, static fn (ContainerInterface $container): ProblemDetailsFactory => new ProblemDetailsFactory(
            $container->get(self::CONFIG_ID)['problem_details'],
            $container->has('kernel.debug') && (bool) $container->get('kernel.debug'),
        ));

        $container->singleton(OpenApiGenerator::class, static function (ContainerInterface $container): OpenApiGenerator {
            $config = $container->get(self::CONFIG_ID);
            $serializer = $container->has(SerializerInterface::class) ? $container->get(SerializerInterface::class) : null;
            $manager = $serializer instanceof SerializerManager ? $serializer : null;

            return new OpenApiGenerator(
                $container->get(RoutingInterface::class),
                $config['openapi'],
                $manager?->getMetadataFactory() ?? new MetadataFactory(),
                $manager?->getNameConverter(),
                $config['pagination'],
            );
        });

        $container->singleton(PageRequestResolver::class, static fn (ContainerInterface $container): PageRequestResolver => new PageRequestResolver($container));

        $resolvers = $container->has(ArgumentResolverInterface::SERVICES_ID) ? (array) $container->get(ArgumentResolverInterface::SERVICES_ID) : [];
        $container->instance(ArgumentResolverInterface::SERVICES_ID, [...$resolvers, PageRequestResolver::class]);
    }

    public static function config(ContainerInterface $container): array
    {
        $config = $container->has(ConfigInterface::class) ? (array) $container->get(ConfigInterface::class)->get(self::CONFIG_KEY, []) : [];

        return self::merge(self::DEFAULT_CONFIG, $config);
    }

    public static function merge(array $defaults, array $config): array
    {
        foreach ($config as $key => $value) {
            $default = $defaults[$key] ?? null;

            if ($value === null && is_array($default)) {
                continue;
            }

            $defaults[$key] = is_array($value) && is_array($default) && $default !== [] && !array_is_list($default) && !in_array($key, ['paths', 'limiters', 'servers'], true)
                ? self::merge($default, $value)
                : $value;
        }

        return $defaults;
    }

    protected static function storage(ContainerInterface $container, mixed $pool): CacheInterface
    {
        if (!$container->has(CacheManagerInterface::class)) {
            return new CachePool('rate_limiter', new ArrayAdapter());
        }

        $manager = $container->get(CacheManagerInterface::class);

        return $manager->pool($pool === null || $pool === '' ? null : (string) $pool);
    }
}