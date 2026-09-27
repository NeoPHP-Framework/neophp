<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\RateLimiter\Contract;

use NeoPHP\Component\Api\RateLimiter\RateLimit;

interface LimiterInterface
{
    public function consume(int $tokens = 1): RateLimit;

    public function peek(): RateLimit;

    public function reset(): void;

    public function getLimit(): int;
}