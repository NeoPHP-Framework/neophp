<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Mapping;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Column
{
    public function __construct(
        public ?string $name = null,
        public ?string $type = null,
        public ?int $length = null,
        public ?bool $nullable = null,
        public bool $unique = false,
        public mixed $default = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public ?string $enumType = null,
    ) {
    }
}