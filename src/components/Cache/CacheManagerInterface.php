<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cache;

use NeoPHP\Component\Cache\Contract\CacheInterface;

interface CacheManagerInterface extends CacheInterface
{
    public function pool(?string $name = null): CacheInterface;

    public function hasPool(string $name): bool;

    public function getPoolNames(): array;

    public function getDefaultPool(): string;

    public function getPoolConfig(string $name): array;
}