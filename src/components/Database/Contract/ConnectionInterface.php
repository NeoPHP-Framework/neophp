<?php

declare(strict_types=1);

namespace NeoPHP\Component\Database\Contract;

use Generator;
use NeoPHP\Component\Database\Result\Result;
use PDO;
use stdClass;

interface ConnectionInterface
{
    public function getName(): string;

    public function getDriver(): DriverInterface;

    public function getParams(): array;

    public function getDatabase(): ?string;

    public function getPdo(): PDO;

    public function isConnected(): bool;

    public function close(): void;

    public function getServerVersion(): string;

    public function executeQuery(string $sql, array $params = []): Result;

    public function executeStatement(string $sql, array $params = []): int;

    public function fetchAssociative(string $sql, array $params = []): ?array;

    public function fetchNumeric(string $sql, array $params = []): ?array;

    public function fetchObject(string $sql, array $params = [], string $class = stdClass::class): ?object;

    public function fetchOne(string $sql, array $params = []): mixed;

    public function fetchAllAssociative(string $sql, array $params = []): array;

    public function fetchAllNumeric(string $sql, array $params = []): array;

    public function fetchAllObjects(string $sql, array $params = [], string $class = stdClass::class): array;

    public function fetchFirstColumn(string $sql, array $params = []): array;

    public function fetchAllKeyValue(string $sql, array $params = []): array;

    public function fetchAllAssociativeIndexed(string $sql, array $params = []): array;

    public function iterateAssociative(string $sql, array $params = []): Generator;

    public function insert(string $table, array $data): int;

    public function update(string $table, array $data, array $criteria): int;

    public function delete(string $table, array $criteria): int;

    public function lastInsertId(?string $sequence = null): string;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    public function inTransaction(): bool;

    public function getTransactionLevel(): int;

    public function transactional(callable $callback): mixed;

    public function quote(mixed $value): string;

    public function quoteIdentifier(string $identifier): string;

    public function setQueryLogger(?QueryLoggerInterface $logger): static;

    public function getQueryLogger(): ?QueryLoggerInterface;
}