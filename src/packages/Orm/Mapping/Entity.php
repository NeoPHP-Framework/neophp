<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Mapping;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Entity
{
    public function __construct(
        public ?string $table = null,
        public ?string $repository = null,
    ) {
    }
}