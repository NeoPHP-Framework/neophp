<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cache\Adapter;

use APCUIterator;
use NeoPHP\Component\Cache\Contract\AbstractAdapter;
use NeoPHP\Component\Cache\Exception\CacheException;

class ApcuAdapter extends AbstractAdapter
{
    public function __construct(string $namespace = '')
    {
        if (!self::isSupported()) {
            throw new CacheException('The "apcu" cache adapter requires the APCu extension, loaded and enabled (set apc.enabled=1, and apc.enable_cli=1 to use it from the console).');
        }

        $this->namespace = $namespace;
    }

    public static function isSupported(): bool
    {
        return extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled();
    }

    public function getName(): string
    {
        return 'apcu';
    }

    public function fetch(string $id): ?string
    {
        $success = false;
        $entry = apcu_fetch($this->prefix() . $id, $success);

        if (!$success || !is_array($entry) || !is_string($entry[0] ?? null)) {
            return null;
        }

        if ($this->isExpired($entry[1] ?? null)) {
            apcu_delete($this->prefix() . $id);

            return null;
        }

        return $entry[0];
    }

    public function save(string $id, string $data, ?int $expiresAt): bool
    {
        $ttl = $expiresAt === null ? 0 : max(1, $expiresAt - time());

        return apcu_store($this->prefix() . $id, [$data, $expiresAt], $ttl);
    }

    public function remove(string $id): bool
    {
        apcu_delete($this->prefix() . $id);

        return true;
    }

    public function clear(): bool
    {
        if ($this->namespace === '') {
            return apcu_clear_cache();
        }

        apcu_delete(new APCUIterator('/^' . preg_quote($this->prefix(), '/') . '/', APC_ITER_KEY));

        return true;
    }

    public function lock(string $id, float $timeout): bool
    {
        $key = $this->prefix() . 'lock:' . $id;
        $ttl = max(1, (int) ceil($timeout)) + 30;

        return $this->waitFor(static fn (): bool => apcu_add($key, getmypid(), $ttl), $timeout);
    }

    public function unlock(string $id): void
    {
        apcu_delete($this->prefix() . 'lock:' . $id);
    }

    protected function prefix(): string
    {
        return $this->namespace === '' ? '' : $this->namespace . ':';
    }
}