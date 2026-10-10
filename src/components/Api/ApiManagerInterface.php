<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api;

use NeoPHP\Component\Api\Cors\CorsManager;
use NeoPHP\Component\Api\OpenApi\OpenApiGenerator;
use NeoPHP\Component\Api\Pagination\Contract\PaginatorInterface;
use NeoPHP\Component\Api\ProblemDetails\ProblemDetailsFactory;
use NeoPHP\Component\Api\RateLimiter\RateLimiterFactory;

interface ApiManagerInterface
{
    /**
     * Returns the CORS manager configured with the cors section of api.yaml.
     *
     * @return CorsManager The CORS manager
     */
    public function cors(): CorsManager;

    /**
     * Returns the factory of the named rate limiters configured in api.yaml.
     *
     * @return RateLimiterFactory The rate limiter factory
     */
    public function rateLimiter(): RateLimiterFactory;

    /**
     * Returns the paginator of arrays, iterables and ORM query builders.
     *
     * @return PaginatorInterface The paginator
     */
    public function paginator(): PaginatorInterface;

    /**
     * Returns the factory of the RFC 7807 problem details responses.
     *
     * @return ProblemDetailsFactory The problem details factory
     */
    public function problems(): ProblemDetailsFactory;

    /**
     * Returns the generator of the OpenAPI 3.1 document built from the routes.
     *
     * @return OpenApiGenerator The OpenAPI generator
     */
    public function openApi(): OpenApiGenerator;
}