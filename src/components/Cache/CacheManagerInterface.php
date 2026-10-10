<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cache;

use NeoPHP\Component\Cache\Contract\CacheInterface;
use NeoPHP\Component\Cache\Exception\CacheException;

interface CacheManagerInterface extends CacheInterface
{
    /**
     * Returns a cache pool, built on first use from its configuration.
     *
     * @param string|null $name Name of the pool, the default pool when null or empty
     * @return CacheInterface The pool
     * @throws CacheException When the pool is not configured, its adapter is not supported, or it uses the database adapter without the Database component
     */
    public function pool(?string $name = null): CacheInterface;

    /**
     * Tells whether a pool is configured.
     *
     * @param string $name Name of the pool
     * @return bool True when the pool is configured
     */
    public function hasPool(string $name): bool;

    /**
     * Returns the names of the configured pools.
     *
     * @return list<int|string> The names of the pools
     */
    public function getPoolNames(): array;

    /**
     * Returns the name of the default pool.
     *
     * @return string The name of the default pool
     */
    public function getDefaultPool(): string;

    /**
     * Returns the configuration of a pool with its defaults: adapter, default_ttl, namespace, lock_timeout and the options of the adapter.
     *
     * @param string $name Name of the pool
     * @return array<mixed> The configuration of the pool
     * @throws CacheException When the pool is not configured
     */
    public function getPoolConfig(string $name): array;
}