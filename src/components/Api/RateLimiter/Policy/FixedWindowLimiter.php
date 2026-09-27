<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\RateLimiter\Policy;

use NeoPHP\Component\Api\RateLimiter\Contract\AbstractLimiter;
use NeoPHP\Component\Api\RateLimiter\RateLimit;

class FixedWindowLimiter extends AbstractLimiter
{
    public function consume(int $tokens = 1): RateLimit
    {
        $this->assertTokens($tokens);
        $now = $this->now();
        $state = $this->load();

        if ($state === null || $now >= (float) $state['start'] + $this->interval) {
            $state = ['start' => $now, 'hits' => 0];
        }

        $end = (float) $state['start'] + $this->interval;

        if ((int) $state['hits'] + $tokens > $this->limit) {
            return $this->result($this->limit - (int) $state['hits'], false, $end, $end);
        }

        $state['hits'] = (int) $state['hits'] + $tokens;

        if ($tokens > 0) {
            $this->save($state, $end);
        }

        return $this->result($this->limit - $state['hits'], true, $now, $end);
    }
}