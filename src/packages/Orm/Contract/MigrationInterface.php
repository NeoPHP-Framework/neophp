<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Contract;

interface MigrationInterface
{
    public function getVersion(): string;

    public function getDescription(): string;

    public function up(): void;

    public function down(): void;

    public function getSql(): array;

    public function clearSql(): void;

    public function isTransactional(): bool;
}