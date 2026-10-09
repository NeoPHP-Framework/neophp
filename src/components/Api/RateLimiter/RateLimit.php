<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\RateLimiter;

use DateTimeImmutable;
use NeoPHP\Component\Http\Exception\TooManyRequestsHttpException;

class RateLimit
{
    public function __construct(
        protected int $limit,
        protected int $remaining,
        protected bool $accepted,
        protected DateTimeImmutable $retryAfter,
        protected DateTimeImmutable $resetAt,
        protected string $name = '',
        protected ?float $now = null,
    ) {
        $this->now ??= microtime(true);
    }

    public function isAccepted(): bool
    {
        return $this->accepted;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getRemaining(): int
    {
        return $this->remaining;
    }

    public function getRetryAfter(): DateTimeImmutable
    {
        return $this->retryAfter;
    }

    public function getRetryAfterSeconds(): int
    {
        return max(0, (int) ceil((float) $this->retryAfter->format('U.u') - (float) $this->now));
    }

    public function getResetAt(): DateTimeImmutable
    {
        return $this->resetAt;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getHeaders(): array
    {
        return [
            'X-RateLimit-Limit' => (string) $this->limit,
            'X-RateLimit-Remaining' => (string) $this->remaining,
            'X-RateLimit-Reset' => (string) $this->resetAt->getTimestamp(),
        ];
    }

    public function ensureAccepted(): static
    {
        if ($this->accepted) {
            return $this;
        }

        $retryAfter = max(1, $this->getRetryAfterSeconds());

        throw new TooManyRequestsHttpException($retryAfter, 'Too many requests: retry in {seconds} second(s).', $this->getHeaders(), [
            'seconds' => $retryAfter,
            'limiter' => $this->name,
            'limit' => $this->limit,
        ]);
    }
}