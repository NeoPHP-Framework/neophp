<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Mapping;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Index
{
    public function __construct(
        public array $columns,
        public ?string $name = null,
        public bool $unique = false,
    ) {
    }
}