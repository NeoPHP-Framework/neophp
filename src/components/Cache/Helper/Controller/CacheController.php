<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cache\Helper\Controller;

use NeoPHP\Component\Cache\Contract\CacheInterface;
use NeoPHP\Component\Cache\Contract\CacheManagerInterface;

trait CacheController
{
    abstract protected function get(string $id): mixed;

    protected function cache(?string $pool = null): CacheInterface
    {
        return $this->get(CacheManagerInterface::class)->pool($pool);
    }
}