<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\RateLimiter\Contract;

use Closure;
use DateTimeImmutable;
use NeoPHP\Component\Api\Exception\RateLimiterException;
use NeoPHP\Component\Api\RateLimiter\RateLimit;
use NeoPHP\Component\Cache\Contract\CacheInterface;

abstract class AbstractLimiter implements LimiterInterface
{
    public function __construct(
        protected string $id,
        protected int $limit,
        protected int $interval,
        protected CacheInterface $storage,
        protected ?Closure $clock = null,
        protected string $name = '',
    ) {
        if ($limit < 1) {
            throw new RateLimiterException('The limit of the rate limiter "{name}" must be greater than 0.', 0, null, ['name' => $name]);
        }

        if ($interval < 1) {
            throw new RateLimiterException('The interval of the rate limiter "{name}" must be at least 1 second.', 0, null, ['name' => $name]);
        }
    }

    public function peek(): RateLimit
    {
        return $this->consume(0);
    }

    public function reset(): void
    {
        $this->storage->delete($this->id);
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    protected function now(): float
    {
        return $this->clock !== null ? (float) ($this->clock)() : microtime(true);
    }

    protected function load(): ?array
    {
        $state = $this->storage->get($this->id);

        return is_array($state) ? $state : null;
    }

    protected function save(array $state, float $expiresAt): void
    {
        $this->storage->set($this->id, $state, max(1, (int) ceil($expiresAt - $this->now())));
    }

    protected function assertTokens(int $tokens): void
    {
        if ($tokens < 0 || $tokens > $this->limit) {
            throw new RateLimiterException('Cannot consume {tokens} token(s) from the rate limiter "{name}": the value must be between 0 and {limit}.', 0, null, [
                'tokens' => $tokens,
                'name' => $this->name,
                'limit' => $this->limit,
            ]);
        }
    }

    protected function result(int $remaining, bool $accepted, float $retryAfter, float $resetAt): RateLimit
    {
        return new RateLimit($this->limit, max(0, $remaining), $accepted, $this->date($retryAfter), $this->date($resetAt), $this->name, $this->now());
    }

    protected function date(float $timestamp): DateTimeImmutable
    {
        return (DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $timestamp)) ?: new DateTimeImmutable('@' . (int) $timestamp));
    }
}