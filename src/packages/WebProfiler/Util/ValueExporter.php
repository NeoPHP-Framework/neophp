<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Util;

use BackedEnum;
use Closure;
use DateTimeInterface;
use JsonSerializable;
use Stringable;
use Throwable;
use UnitEnum;

class ValueExporter
{
    public const DEFAULT_DEPTH = 5;

    public const MAX_ITEMS = 200;

    public const MAX_STRING = 10000;

    public static function export(mixed $value, int $depth = self::DEFAULT_DEPTH): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => is_finite($value) ? $value : (string) $value,
            is_string($value) => self::string($value),
            is_array($value) => self::exportArray($value, $depth),
            is_resource($value) => sprintf('resource(%s)', get_resource_type($value)),
            is_object($value) => self::exportObject($value, $depth),
            default => get_debug_type($value),
        };
    }

    public static function toString(mixed $value): string
    {
        $value = self::export($value);

        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        };
    }

    public static function formatBytes(int|float $bytes, int $precision = 1): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB'];
        $index = 0;

        while (abs($bytes) >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return round($bytes, $index === 0 ? 0 : $precision) . ' ' . $units[$index];
    }

    public static function formatDuration(float $milliseconds): string
    {
        return $milliseconds >= 1000 ? round($milliseconds / 1000, 2) . ' s' : round($milliseconds, 1) . ' ms';
    }

    protected static function string(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = sprintf('binary(%d bytes)', strlen($value));
        }

        return strlen($value) > self::MAX_STRING ? substr($value, 0, self::MAX_STRING) . '...' : $value;
    }

    protected static function exportArray(array $value, int $depth): array|string
    {
        if ($depth <= 0) {
            return sprintf('array(%d)', count($value));
        }

        $result = [];
        $count = 0;

        foreach ($value as $key => $item) {
            if (++$count > self::MAX_ITEMS) {
                $result['...'] = sprintf('%d more item(s)', count($value) - self::MAX_ITEMS);
                break;
            }

            $result[$key] = self::export($item, $depth - 1);
        }

        return $result;
    }

    protected static function exportObject(object $value, int $depth): mixed
    {
        try {
            return match (true) {
                $value instanceof BackedEnum => $value::class . '::' . $value->name . ' (' . $value->value . ')',
                $value instanceof UnitEnum => $value::class . '::' . $value->name,
                $value instanceof DateTimeInterface => $value->format(DateTimeInterface::RFC3339_EXTENDED),
                $value instanceof Closure => 'Closure',
                $value instanceof Throwable => sprintf('%s: %s', $value::class, $value->getMessage()),
                $value instanceof JsonSerializable && $depth > 0 => self::export($value->jsonSerialize(), $depth - 1),
                $value instanceof Stringable => self::string((string) $value),
                $depth > 0 => ['__class' => $value::class, ...self::exportArray(get_object_vars($value), $depth - 1)],
                default => 'object(' . $value::class . ')',
            };
        } catch (Throwable) {
            return 'object(' . $value::class . ')';
        }
    }
}