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
    public function cors(): CorsManager;

    public function rateLimiter(): RateLimiterFactory;

    public function paginator(): PaginatorInterface;

    public function problems(): ProblemDetailsFactory;

    public function openApi(): OpenApiGenerator;
}