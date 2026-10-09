<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Contract;

use NeoPHP\Package\WebProfiler\Model\Profile;

interface ProfileStorageInterface
{
    public function write(Profile $profile): void;

    public function read(string $token): ?Profile;

    public function find(int $limit = 50, array $filters = []): array;

    public function purge(): int;

    public function clear(): int;
}