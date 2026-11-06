<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Throttle;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Package\Security\Exception\SecurityException;
use NeoPHP\Package\Security\Exception\TooManyLoginAttemptsException;
use NeoPHP\Component\Cache\Contract\CacheInterface;

class LoginThrottler
{
    public const IP_FACTOR = 5;

    public const GC_DIVISOR = 100;

    public const CACHE_PREFIX = 'login_throttling.';

    public const LOCK_TIMEOUT = 1.0;

    public function __construct(protected string $directory, protected int $maxAttempts = 5, protected int $interval = 60, protected int $maxAccountAttempts = 0, protected ?CacheInterface $cache = null)
    {
        if ($maxAttempts < 1 || $interval < 1) {
            throw new SecurityException('The login throttling "max_attempts" and "interval" options must be positive integers.');
        }
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getInterval(): int
    {
        return $this->interval;
    }

    public function consume(Request $request, string $identifier): void
    {
        $retryAfter = 0;
        $limits = [[$this->userKey($request, $identifier), $this->maxAttempts], [$this->ipKey($request), $this->maxAttempts * self::IP_FACTOR]];

        if ($this->maxAccountAttempts > 0) {
            $limits[] = [$this->accountKey($identifier), $this->maxAccountAttempts];
        }

        foreach ($limits as [$key, $limit]) {
            $this->update($key, function (array $attempts) use ($limit, &$retryAfter): array {
                if (count($attempts) >= $limit) {
                    $retryAfter = max($retryAfter, $attempts[count($attempts) - $limit] + $this->interval - time());

                    return $attempts;
                }

                $attempts[] = time();

                return $attempts;
            });
        }

        if (random_int(1, self::GC_DIVISOR) === 1) {
            $this->gc();
        }

        if ($retryAfter > 0) {
            throw new TooManyLoginAttemptsException($retryAfter);
        }
    }

    public function gc(): int
    {
        $removed = 0;
        $limit = time() - $this->interval;

        foreach (glob(rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if ((int) @filemtime($file) < $limit && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    public function reset(Request $request, string $identifier): void
    {
        $key = $this->userKey($request, $identifier);
        $file = $this->file($key);

        if ($this->cache !== null) {
            $this->cache->delete(self::CACHE_PREFIX . $key);
        } elseif (is_file($file)) {
            @unlink($file);
        }

        $removeLast = static function (array $attempts): array {
            array_pop($attempts);

            return $attempts;
        };

        $this->update($this->ipKey($request), $removeLast);

        if ($this->maxAccountAttempts > 0) {
            $this->update($this->accountKey($identifier), $removeLast);
        }
    }

    protected function update(string $key, callable $callback): void
    {
        if ($this->cache !== null) {
            $this->updateCache($this->cache, $key, $callback);

            return;
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new SecurityException('Unable to create the login throttling directory "{directory}".', 0, null, ['directory' => $this->directory]);
        }

        $file = $this->file($key);
        $handle = fopen($file, 'c+');
        $empty = false;

        if ($handle === false) {
            throw new SecurityException('Unable to open the login throttling file of "{key}".', 0, null, ['key' => $key]);
        }

        try {
            flock($handle, LOCK_EX);
            $attempts = $callback($this->prune(json_decode((string) stream_get_contents($handle), true)));
            $empty = $attempts === [];
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode(array_values($attempts)));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        if ($empty) {
            @unlink($file);
        }
    }

    protected function updateCache(CacheInterface $cache, string $key, callable $callback): void
    {
        $id = self::CACHE_PREFIX . $key;
        $adapter = $cache->getAdapter();
        $locked = $adapter->lock($id, self::LOCK_TIMEOUT);

        try {
            $attempts = $callback($this->prune($cache->get($id)));

            if ($attempts === []) {
                $cache->delete($id);
            } else {
                $cache->set($id, array_values($attempts), $this->interval);
            }
        } finally {
            if ($locked) {
                $adapter->unlock($id);
            }
        }
    }

    protected function prune(mixed $attempts): array
    {
        $limit = time() - $this->interval;

        return array_values(array_filter(is_array($attempts) ? array_map('intval', $attempts) : [], static fn (int $time): bool => $time > $limit));
    }

    protected function userKey(Request $request, string $identifier): string
    {
        return 'user_' . hash('sha256', ($request->getClientIp() ?? '') . '|' . strtolower($identifier));
    }

    protected function accountKey(string $identifier): string
    {
        return 'account_' . hash('sha256', strtolower($identifier));
    }

    protected function ipKey(Request $request): string
    {
        return 'ip_' . hash('sha256', $request->getClientIp() ?? '');
    }

    protected function file(string $key): string
    {
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $key . '.json';
    }
}