<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Provider;

use NeoPHP\Component\Api\ApiManager;
use NeoPHP\Component\Api\ApiManagerInterface;
use NeoPHP\Component\Api\ArgumentResolver\PageRequestResolver;
use NeoPHP\Component\Api\Cors\CorsManager;
use NeoPHP\Component\Api\OpenApi\OpenApiGenerator;
use NeoPHP\Component\Api\Pagination\Contract\PaginatorInterface;
use NeoPHP\Component\Api\Pagination\Paginator;
use NeoPHP\Component\Api\ProblemDetails\ProblemDetailsFactory;
use NeoPHP\Component\Api\RateLimiter\RateLimiterFactory;
use NeoPHP\Component\Api\RateLimiter\RequestKeyResolver;
use NeoPHP\Component\Cache\Adapter\ArrayAdapter;
use NeoPHP\Component\Cache\CacheManagerInterface;
use NeoPHP\Component\Cache\Contract\CacheInterface;
use NeoPHP\Component\Cache\Pool\CachePool;
use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Controller\Contract\ArgumentResolverInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Routing\RoutingManagerInterface;
use NeoPHP\Component\Serializer\Mapping\MetadataFactory;
use NeoPHP\Component\Serializer\SerializerManager;
use NeoPHP\Component\Serializer\SerializerManagerInterface;

/**
 * @internal
 */
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

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(self::CONFIG_ID, static fn (ContainerManagerInterface $container): array => self::config($container));

        $container->singleton(ApiManagerInterface::class, static fn (ContainerManagerInterface $container): ApiManagerInterface => new ApiManager($container));
        $container->alias(ApiManager::class, ApiManagerInterface::class);

        $container->singleton(CorsManager::class, static fn (ContainerManagerInterface $container): CorsManager => new CorsManager($container->get(self::CONFIG_ID)['cors']));

        $container->singleton(RateLimiterFactory::class, static function (ContainerManagerInterface $container): RateLimiterFactory {
            $config = $container->get(self::CONFIG_ID)['rate_limiter'];

            return new RateLimiterFactory((array) $config['limiters'], static fn (): CacheInterface => self::storage($container, $config['cache_pool']));
        });

        $container->singleton(RequestKeyResolver::class, static fn (ContainerManagerInterface $container): RequestKeyResolver => new RequestKeyResolver($container));

        $container->singleton(PaginatorInterface::class, static fn (ContainerManagerInterface $container): PaginatorInterface => new Paginator(
            $container->get(self::CONFIG_ID)['pagination'],
            static fn (): ?Request => $container->has(Request::class) ? $container->get(Request::class) : null,
        ));
        $container->alias(Paginator::class, PaginatorInterface::class);

        $container->singleton(ProblemDetailsFactory::class, static fn (ContainerManagerInterface $container): ProblemDetailsFactory => new ProblemDetailsFactory(
            $container->get(self::CONFIG_ID)['problem_details'],
            $container->has('kernel.debug') && (bool) $container->get('kernel.debug'),
        ));

        $container->singleton(OpenApiGenerator::class, static function (ContainerManagerInterface $container): OpenApiGenerator {
            $config = $container->get(self::CONFIG_ID);
            $serializer = $container->has(SerializerManagerInterface::class) ? $container->get(SerializerManagerInterface::class) : null;
            $manager = $serializer instanceof SerializerManager ? $serializer : null;

            return new OpenApiGenerator(
                $container->get(RoutingManagerInterface::class),
                $config['openapi'],
                $manager?->getMetadataFactory() ?? new MetadataFactory(),
                $manager?->getNameConverter(),
                $config['pagination'],
            );
        });

        $container->singleton(PageRequestResolver::class, static fn (ContainerManagerInterface $container): PageRequestResolver => new PageRequestResolver($container));

        $resolvers = $container->has(ArgumentResolverInterface::SERVICES_ID) ? (array) $container->get(ArgumentResolverInterface::SERVICES_ID) : [];
        $container->instance(ArgumentResolverInterface::SERVICES_ID, [...$resolvers, PageRequestResolver::class]);
    }

    public static function config(ContainerManagerInterface $container): array
    {
        $config = $container->has(ConfigManagerInterface::class) ? (array) $container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) : [];

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

    protected static function storage(ContainerManagerInterface $container, mixed $pool): CacheInterface
    {
        if (!$container->has(CacheManagerInterface::class)) {
            return new CachePool('rate_limiter', new ArrayAdapter());
        }

        $manager = $container->get(CacheManagerInterface::class);

        return $manager->pool($pool === null || $pool === '' ? null : (string) $pool);
    }
}