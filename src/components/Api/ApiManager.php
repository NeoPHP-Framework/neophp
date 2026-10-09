<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api;

use NeoPHP\Component\Api\Cors\CorsManager;
use NeoPHP\Component\Api\OpenApi\OpenApiGenerator;
use NeoPHP\Component\Api\Pagination\Contract\PaginatorInterface;
use NeoPHP\Component\Api\ProblemDetails\ProblemDetailsFactory;
use NeoPHP\Component\Api\Provider\ApiProvider;
use NeoPHP\Component\Api\RateLimiter\RateLimiterFactory;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Http\HttpManager;
use NeoPHP\Component\Kernel\Attribute\Component;
use NeoPHP\Component\Routing\RoutingManager;

#[Component(provider: ApiProvider::class, requires: [RoutingManager::class, HttpManager::class])]
final class ApiManager implements ApiManagerInterface
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    public function cors(): CorsManager
    {
        return $this->container->get(CorsManager::class);
    }

    public function rateLimiter(): RateLimiterFactory
    {
        return $this->container->get(RateLimiterFactory::class);
    }

    public function paginator(): PaginatorInterface
    {
        return $this->container->get(PaginatorInterface::class);
    }

    public function problems(): ProblemDetailsFactory
    {
        return $this->container->get(ProblemDetailsFactory::class);
    }

    public function openApi(): OpenApiGenerator
    {
        return $this->container->get(OpenApiGenerator::class);
    }
}