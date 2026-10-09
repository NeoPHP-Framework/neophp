<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Schema;

class Column
{
    public function __construct(
        public string $name,
        public string $type,
        public ?int $length = null,
        public bool $nullable = false,
        public ?string $default = null,
        public bool $autoincrement = false,
        public ?int $precision = null,
        public ?int $scale = null,
    ) {
    }

    public static function normalizeDefault(mixed $default): ?string
    {
        return match (true) {
            $default === null => null,
            is_bool($default) => $default ? '1' : '0',
            default => (string) $default,
        };
    }
}