<?php

declare(strict_types=1);

namespace NeoPHP\Component\Database\Logger;

use BackedEnum;
use Closure;
use DateTimeInterface;
use NeoPHP\Component\Database\Contract\QueryLoggerInterface;
use Stringable;
use UnitEnum;

class QueryLogger implements QueryLoggerInterface
{
    public const MAX_QUERIES = 1000;

    public const MAX_PARAM_LENGTH = 200;

    public const TRACE_LIMIT = 40;

    protected array $queries = [];

    protected int $dropped = 0;

    protected float $totalTime = 0.0;

    protected string $frameworkPath;

    public function __construct(protected int $maxQueries = self::MAX_QUERIES, protected ?Closure $listener = null, ?string $frameworkPath = null)
    {
        $this->frameworkPath = rtrim($frameworkPath ?? dirname(__DIR__, 3), '/\\') . DIRECTORY_SEPARATOR;
    }

    public function setListener(?Closure $listener): static
    {
        $this->listener = $listener;

        return $this;
    }

    public function log(string $connection, string $sql, array $params, float $start, float $duration, ?int $rows = null, ?string $error = null, string $type = 'query'): void
    {
        $this->totalTime += $duration;

        if (count($this->queries) >= $this->maxQueries) {
            $this->dropped++;

            return;
        }

        $query = [
            'index' => count($this->queries),
            'connection' => $connection,
            'type' => $type,
            'sql' => $sql,
            'params' => $this->exportParams($params),
            'start' => $start,
            'duration' => round($duration, 3),
            'rows' => $rows,
            'caller' => $this->findCaller(),
            'error' => $error,
        ];

        $this->queries[] = $query;

        if ($this->listener !== null) {
            ($this->listener)($query);
        }
    }

    public function getQueries(): array
    {
        return $this->queries;
    }

    public function count(): int
    {
        return count($this->queries) + $this->dropped;
    }

    public function getTotalTime(): float
    {
        return $this->totalTime;
    }

    public function getDropped(): int
    {
        return $this->dropped;
    }

    public function reset(): void
    {
        $this->queries = [];
        $this->dropped = 0;
        $this->totalTime = 0.0;
    }

    protected function findCaller(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::TRACE_LIMIT) as $frame) {
            $file = $frame['file'] ?? null;

            if ($file !== null && !str_starts_with($file, $this->frameworkPath)) {
                return $file . ':' . ($frame['line'] ?? 0);
            }
        }

        return null;
    }

    protected function exportParams(array $params): array
    {
        $exported = [];

        foreach ($params as $key => $value) {
            $exported[$key] = $this->exportValue($value);
        }

        return $exported;
    }

    protected function exportValue(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            is_string($value) => mb_strlen($value) > self::MAX_PARAM_LENGTH ? mb_substr($value, 0, self::MAX_PARAM_LENGTH) . '…' : $value,
            is_array($value) => $this->exportParams($value),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof Stringable => $this->exportValue((string) $value),
            is_resource($value) => '(resource)',
            default => '(' . get_debug_type($value) . ')',
        };
    }
}