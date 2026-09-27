<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\RateLimiter;

use Closure;
use DateInterval;
use DateTimeImmutable;
use NeoPHP\Component\Api\Exception\InvalidConfigurationException;
use NeoPHP\Component\Api\Exception\RateLimiterException;
use NeoPHP\Component\Api\RateLimiter\Contract\LimiterInterface;
use NeoPHP\Component\Api\RateLimiter\Policy\FixedWindowLimiter;
use NeoPHP\Component\Api\RateLimiter\Policy\NoLimiter;
use NeoPHP\Component\Api\RateLimiter\Policy\SlidingWindowLimiter;
use NeoPHP\Component\Api\RateLimiter\Policy\TokenBucketLimiter;
use NeoPHP\Component\Cache\Contract\CacheInterface;
use Throwable;

class RateLimiterFactory
{
    public const POLICIES = ['fixed_window', 'sliding_window', 'token_bucket', 'no_limit'];

    public const KEY_PREFIX = 'rate_limiter.';

    protected array $limiters = [];

    public function __construct(array $limiters, protected CacheInterface|Closure $storage, protected ?Closure $clock = null)
    {
        foreach ($limiters as $name => $config) {
            $this->limiters[(string) $name] = $this->normalize((string) $name, (array) $config);
        }
    }

    public function create(string $name, ?string $key = null): LimiterInterface
    {
        $config = $this->getConfig($name);
        $id = self::KEY_PREFIX . hash('xxh128', $name . "\0" . ($key ?? ''));

        return match ($config['policy']) {
            'fixed_window' => new FixedWindowLimiter($id, $config['limit'], $config['interval'], $this->storage(), $this->clock, $name),
            'sliding_window' => new SlidingWindowLimiter($id, $config['limit'], $config['interval'], $this->storage(), $this->clock, $name),
            'token_bucket' => new TokenBucketLimiter($id, $config['limit'], $config['rate']['interval'], $this->storage(), $this->clock, $name, $config['rate']['amount']),
            default => new NoLimiter($name),
        };
    }

    public function has(string $name): bool
    {
        return isset($this->limiters[$name]);
    }

    public function getNames(): array
    {
        return array_keys($this->limiters);
    }

    public function getConfig(string $name): array
    {
        return $this->limiters[$name] ?? throw new RateLimiterException('Unknown rate limiter "{name}": define it under rate_limiter.limiters in config/framework/api.yaml (available: {available}).', 0, null, [
            'name' => $name,
            'available' => $this->limiters === [] ? 'none' : implode(', ', array_keys($this->limiters)),
        ]);
    }

    public static function parseInterval(int|string|DateInterval $interval): int
    {
        if (is_int($interval) || (is_string($interval) && ctype_digit(trim($interval)))) {
            return (int) $interval;
        }

        try {
            $origin = new DateTimeImmutable('@0');
            $date = $interval instanceof DateInterval
                ? $origin->add($interval)
                : (str_starts_with(strtoupper(trim($interval)), 'P') ? $origin->add(new DateInterval(strtoupper(trim($interval)))) : $origin->modify('+' . ltrim(trim($interval), '+')));
        } catch (Throwable $exception) {
            throw new InvalidConfigurationException('Invalid rate limiter interval "{interval}": use seconds, "1 minute", "15 minutes", "1 hour" or an ISO 8601 duration.', 0, $exception, ['interval' => is_string($interval) ? $interval : 'DateInterval']);
        }

        if ($date === false || $date->getTimestamp() <= 0) {
            throw new InvalidConfigurationException('Invalid rate limiter interval "{interval}": use seconds, "1 minute", "15 minutes", "1 hour" or an ISO 8601 duration.', 0, null, ['interval' => is_string($interval) ? $interval : 'DateInterval']);
        }

        return $date->getTimestamp();
    }

    protected function normalize(string $name, array $config): array
    {
        $policy = (string) ($config['policy'] ?? 'fixed_window');

        if (!in_array($policy, self::POLICIES, true)) {
            throw new InvalidConfigurationException('Unknown policy "{policy}" for the rate limiter "{name}": use {policies}.', 0, null, [
                'policy' => $policy,
                'name' => $name,
                'policies' => implode(', ', self::POLICIES),
            ]);
        }

        $limit = (int) ($config['limit'] ?? 0);

        if ($policy !== 'no_limit' && $limit < 1) {
            throw new InvalidConfigurationException('The rate limiter "{name}" needs a "limit" greater than 0.', 0, null, ['name' => $name]);
        }

        $interval = self::parseInterval($config['interval'] ?? 60);
        $rate = is_array($config['rate'] ?? null) ? $config['rate'] : [];

        return [
            'policy' => $policy,
            'limit' => $limit,
            'interval' => $interval,
            'rate' => [
                'amount' => (int) ($rate['amount'] ?? $limit),
                'interval' => isset($rate['interval']) ? self::parseInterval($rate['interval']) : $interval,
            ],
        ];
    }

    protected function storage(): CacheInterface
    {
        if ($this->storage instanceof Closure) {
            $this->storage = ($this->storage)();
        }

        return $this->storage;
    }
}