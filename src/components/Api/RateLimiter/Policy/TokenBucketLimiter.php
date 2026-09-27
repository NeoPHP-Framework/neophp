<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\RateLimiter\Policy;

use Closure;
use NeoPHP\Component\Api\Exception\RateLimiterException;
use NeoPHP\Component\Api\RateLimiter\Contract\AbstractLimiter;
use NeoPHP\Component\Api\RateLimiter\RateLimit;
use NeoPHP\Component\Cache\Contract\CacheInterface;

class TokenBucketLimiter extends AbstractLimiter
{
    public function __construct(
        string $id,
        int $limit,
        int $interval,
        CacheInterface $storage,
        ?Closure $clock = null,
        string $name = '',
        protected int $amount = 0,
    ) {
        parent::__construct($id, $limit, $interval, $storage, $clock, $name);

        $this->amount = $amount > 0 ? $amount : $limit;

        if ($this->amount < 1) {
            throw new RateLimiterException('The refill amount of the rate limiter "{name}" must be greater than 0.', 0, null, ['name' => $name]);
        }
    }

    public function consume(int $tokens = 1): RateLimit
    {
        $this->assertTokens($tokens);
        $now = $this->now();
        $rate = $this->amount / $this->interval;
        $state = $this->load() ?? ['tokens' => (float) $this->limit, 'time' => $now];
        $available = min((float) $this->limit, (float) $state['tokens'] + max(0.0, $now - (float) $state['time']) * $rate);

        if ($available + 1e-9 < $tokens) {
            $retryAt = $now + ($tokens - $available) / $rate;

            return $this->result((int) floor($available), false, $retryAt, $now + ($this->limit - $available) / $rate);
        }

        $available -= $tokens;
        $full = $now + ($this->limit - $available) / $rate;

        if ($tokens > 0) {
            $this->save(['tokens' => $available, 'time' => $now], $full);
        }

        return $this->result((int) floor($available + 1e-9), true, $now, $full);
    }
}