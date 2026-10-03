<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Retry;

class RetryStrategy
{
    public function __construct(
        protected int $maxAttempts = 3,
        protected float $delay = 1.0,
        protected float $multiplier = 2.0,
        protected float $maxDelay = 3600.0,
    ) {
    }

    public static function fromConfig(array $config): self
    {
        return new self(
            max(1, (int) ($config['max_attempts'] ?? 3)),
            max(0.0, (float) ($config['delay'] ?? 1)),
            max(1.0, (float) ($config['multiplier'] ?? 2)),
            max(0.0, (float) ($config['max_delay'] ?? 3600)),
        );
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getDelay(int $attempt): int
    {
        $delay = $this->delay * ($this->multiplier ** max(0, $attempt - 1));

        if ($this->maxDelay > 0) {
            $delay = min($delay, $this->maxDelay);
        }

        return (int) ceil($delay);
    }
}