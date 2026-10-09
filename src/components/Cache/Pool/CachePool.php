<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cache\Pool;

use NeoPHP\Component\Cache\Contract\AbstractCache;
use NeoPHP\Component\Cache\Contract\AdapterInterface;

class CachePool extends AbstractCache
{
    public function __construct(string $name, AdapterInterface $adapter, ?int $defaultTtl = null, float $lockTimeout = self::DEFAULT_LOCK_TIMEOUT, ?string $secret = null)
    {
        $this->name = $name;
        $this->adapter = $adapter;
        $this->defaultTtl = $defaultTtl;
        $this->lockTimeout = $lockTimeout;
        $this->secret = $secret;
    }
}