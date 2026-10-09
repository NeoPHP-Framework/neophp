<?php

declare(strict_types=1);

namespace NeoPHP\Component\Database\Contract;

use PDO;

interface DriverInterface
{
    public function getName(): string;

    public function getExtension(): string;

    public function isAvailable(): bool;

    public function getDsn(array $params, bool $withDatabase = true): string;

    public function connect(array $params, bool $withDatabase = true): PDO;

    public function quoteIdentifier(string $identifier): string;

    public function databaseExists(array $params): bool;

    public function createDatabase(array $params): bool;

    public function dropDatabase(array $params): bool;
}