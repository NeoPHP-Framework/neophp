<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\RateLimiter\Policy;

use NeoPHP\Component\Api\RateLimiter\Contract\AbstractLimiter;
use NeoPHP\Component\Api\RateLimiter\RateLimit;

class SlidingWindowLimiter extends AbstractLimiter
{
    public function consume(int $tokens = 1): RateLimit
    {
        $this->assertTokens($tokens);
        $now = $this->now();
        $state = $this->load() ?? ['start' => $now, 'hits' => 0, 'previous' => 0];
        $elapsed = $now - (float) $state['start'];

        if ($elapsed >= $this->interval) {
            $windows = (int) floor($elapsed / $this->interval);
            $state['previous'] = $windows === 1 ? (int) $state['hits'] : 0;
            $state['hits'] = 0;
            $state['start'] = (float) $state['start'] + $windows * $this->interval;
        }

        $start = (float) $state['start'];
        $end = $start + $this->interval;
        $weight = 1 - (($now - $start) / $this->interval);
        $hits = (int) ceil((int) $state['previous'] * $weight - 1e-9) + (int) $state['hits'];

        if ($hits + $tokens > $this->limit) {
            return $this->result($this->limit - $hits, false, $this->retryAt($state, $tokens, $start, $end), $end);
        }

        $state['hits'] = (int) $state['hits'] + $tokens;

        if ($tokens > 0) {
            $this->save($state, $end + $this->interval);
        }

        return $this->result($this->limit - $hits - $tokens, true, $now, $end);
    }

    protected function retryAt(array $state, int $tokens, float $start, float $end): float
    {
        $previous = (int) $state['previous'];
        $available = $this->limit - (int) $state['hits'] - $tokens;

        if ($previous === 0 || $available < 0) {
            return $end;
        }

        $weight = $available / $previous;

        return min($end, $start + max(0.0, 1 - $weight) * $this->interval);
    }
}