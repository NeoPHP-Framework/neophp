<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Type;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use NeoPHP\Package\Orm\Exception\MappingException;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionProperty;
use Stringable;

class Type
{
    public const STRING = 'string';
    public const TEXT = 'text';
    public const INTEGER = 'integer';
    public const SMALLINT = 'smallint';
    public const BIGINT = 'bigint';
    public const FLOAT = 'float';
    public const DECIMAL = 'decimal';
    public const BOOLEAN = 'boolean';
    public const DATETIME = 'datetime';
    public const DATETIME_IMMUTABLE = 'datetime_immutable';
    public const DATE = 'date';
    public const DATE_IMMUTABLE = 'date_immutable';
    public const TIME = 'time';
    public const TIME_IMMUTABLE = 'time_immutable';
    public const JSON = 'json';
    public const GUID = 'guid';

    public const TYPES = [
        self::STRING => self::STRING,
        self::TEXT => self::TEXT,
        self::INTEGER => self::INTEGER,
        self::SMALLINT => self::SMALLINT,
        self::BIGINT => self::BIGINT,
        self::FLOAT => self::FLOAT,
        self::DECIMAL => self::DECIMAL,
        self::BOOLEAN => self::BOOLEAN,
        self::DATETIME => self::DATETIME,
        self::DATETIME_IMMUTABLE => self::DATETIME,
        self::DATE => self::DATE,
        self::DATE_IMMUTABLE => self::DATE,
        self::TIME => self::TIME,
        self::TIME_IMMUTABLE => self::TIME,
        self::JSON => self::JSON,
        self::GUID => self::GUID,
    ];

    public const ALIASES = [
        'int' => self::INTEGER,
        'bool' => self::BOOLEAN,
        'double' => self::FLOAT,
        'array' => self::JSON,
        'uuid' => self::GUID,
    ];

    public const FORMATS = [
        self::DATETIME => 'Y-m-d H:i:s',
        self::DATE => 'Y-m-d',
        self::TIME => 'H:i:s',
    ];

    public static function normalize(string $type): string
    {
        $type = strtolower($type);
        $type = self::ALIASES[$type] ?? $type;

        if (!isset(self::TYPES[$type])) {
            throw new MappingException('Unknown column type "{type}". Available types: {types}.', 0, null, [
                'type' => $type,
                'types' => implode(', ', array_keys(self::TYPES)),
            ]);
        }

        return $type;
    }

    public static function getSchemaType(string $type): string
    {
        return self::TYPES[self::normalize($type)];
    }

    public static function infer(ReflectionProperty $property): array
    {
        $type = $property->getType();

        if (!$type instanceof ReflectionNamedType) {
            return [self::STRING, null, true];
        }

        $name = $type->getName();
        $nullable = $type->allowsNull();

        if (enum_exists($name) && is_subclass_of($name, BackedEnum::class)) {
            $backing = (string) (new ReflectionEnum($name))->getBackingType();

            return [$backing === 'int' ? self::INTEGER : self::STRING, $name, $nullable];
        }

        return [match (true) {
            $name === 'int' => self::INTEGER,
            $name === 'bool' => self::BOOLEAN,
            $name === 'float' => self::FLOAT,
            $name === 'array' => self::JSON,
            $name === DateTimeImmutable::class => self::DATETIME_IMMUTABLE,
            $name === DateTime::class, $name === DateTimeInterface::class => self::DATETIME,
            default => self::STRING,
        }, null, $nullable];
    }

    public static function toDatabase(mixed $value, string $type, ?string $enumType = null): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        return match (self::getSchemaType($type)) {
            self::INTEGER, self::SMALLINT, self::BIGINT => (int) $value,
            self::FLOAT => (float) $value,
            self::DECIMAL => (string) $value,
            self::BOOLEAN => (bool) $value,
            self::DATETIME, self::DATE, self::TIME => $value instanceof DateTimeInterface ? $value->format(self::FORMATS[self::getSchemaType($type)]) : (string) $value,
            self::JSON => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            default => $value instanceof Stringable || is_scalar($value) ? (string) $value : $value,
        };
    }

    public static function toPhp(mixed $value, string $type, ?string $enumType = null, ?int $scale = null): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = self::normalize($type);

        if ($enumType !== null) {
            return $enumType::from(is_numeric($value) && self::getSchemaType($type) !== self::STRING ? (int) $value : (string) $value);
        }

        return match ($type) {
            self::INTEGER, self::SMALLINT => (int) $value,
            self::BIGINT => is_int($value) ? $value : (PHP_INT_SIZE >= 8 ? (int) $value : (string) $value),
            self::FLOAT => (float) $value,
            self::DECIMAL => self::formatDecimal((string) $value, $scale),
            self::BOOLEAN => is_bool($value) ? $value : in_array(strtolower((string) $value), ['1', 't', 'true', 'y', 'yes'], true),
            self::DATETIME, self::DATE, self::TIME => $value instanceof DateTime ? $value : new DateTime((string) $value),
            self::DATETIME_IMMUTABLE, self::DATE_IMMUTABLE, self::TIME_IMMUTABLE => $value instanceof DateTimeImmutable ? $value : new DateTimeImmutable((string) $value),
            self::JSON => is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value,
            default => is_resource($value) ? (string) stream_get_contents($value) : (string) $value,
        };
    }

    public static function formatDecimal(string $value, ?int $scale): string
    {
        if ($scale === null || !is_numeric($value) || stripos($value, 'e') !== false) {
            return $value;
        }

        $parts = explode('.', $value, 2);

        if ($scale === 0) {
            return $parts[0];
        }

        return $parts[0] . '.' . substr(str_pad($parts[1] ?? '', $scale, '0'), 0, $scale);
    }
}