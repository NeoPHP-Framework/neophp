<?php

declare(strict_types=1);

namespace NeoPHP\Process\Package;

interface PackageManagerInterface
{
    public const STATUS_CREATED = 'created';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_OVERWRITTEN = 'overwritten';
    public const STATUS_CHANGED = 'changed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_REMOVED = 'removed';

    public function all(): array;

    public function find(string $package): ?array;

    public function install(string $package, bool $dev = false, bool $force = false): array;

    public function update(?string $package = null, bool $force = false): array;

    public function remove(string $package, bool $purge = false): array;

    public function publishConfig(string $package, bool $force = false): array;

    public function addPathRepository(string $path): bool;

    public function getConfigDirectory(string $package): string;

    public function clearCache(): int;
}