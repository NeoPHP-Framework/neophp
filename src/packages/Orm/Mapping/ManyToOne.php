<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Mapping;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class ManyToOne
{
    public function __construct(
        public string $target,
        public ?string $inversedBy = null,
        public ?string $joinColumn = null,
        public bool $nullable = true,
        public ?string $onDelete = null,
        public array $cascade = [],
        public string $fetch = 'lazy',
    ) {
    }
}