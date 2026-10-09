<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cache\Helper\Controller;

use NeoPHP\Component\Cache\CacheManagerInterface;
use NeoPHP\Component\Cache\Contract\CacheInterface;

trait CacheController
{
    abstract protected function get(string $id): mixed;

    protected function cache(?string $pool = null): CacheInterface
    {
        return $this->get(CacheManagerInterface::class)->pool($pool);
    }
}