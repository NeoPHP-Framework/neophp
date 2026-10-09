<?php

declare(strict_types=1);

namespace NeoPHP\Process\Installer;

interface InstallerManagerInterface
{
    public const STATUS_CREATED = 'created';
    public const STATUS_OVERWRITTEN = 'overwritten';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_UPDATED = 'updated';

    public function install(string $projectDir, bool $force = false): array;

    public function getSkeletonDir(): string;

    public function getFiles(): array;

    public function getDirectories(): array;
}