<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\RateLimiter\Policy;

use DateTimeImmutable;
use NeoPHP\Component\Api\RateLimiter\Contract\LimiterInterface;
use NeoPHP\Component\Api\RateLimiter\RateLimit;

class NoLimiter implements LimiterInterface
{
    public function __construct(protected string $name = '')
    {
    }

    public function consume(int $tokens = 1): RateLimit
    {
        $now = new DateTimeImmutable();

        return new RateLimit(PHP_INT_MAX, PHP_INT_MAX, true, $now, $now, $this->name);
    }

    public function peek(): RateLimit
    {
        return $this->consume(0);
    }

    public function reset(): void
    {
    }

    public function getLimit(): int
    {
        return PHP_INT_MAX;
    }
}