<?php

declare(strict_types=1);

namespace NeoPHP\Component\Database\Contract;

interface QueryLoggerInterface
{
    public function log(string $connection, string $sql, array $params, float $start, float $duration, ?int $rows = null, ?string $error = null, string $type = 'query'): void;

    public function getQueries(): array;

    public function count(): int;

    public function getTotalTime(): float;

    public function getDropped(): int;

    public function reset(): void;
}