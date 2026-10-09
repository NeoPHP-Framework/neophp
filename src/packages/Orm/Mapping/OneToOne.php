<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Mapping;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class OneToOne
{
    public function __construct(
        public string $target,
        public ?string $mappedBy = null,
        public ?string $inversedBy = null,
        public ?string $joinColumn = null,
        public bool $nullable = true,
        public ?string $onDelete = null,
        public array $cascade = [],
        public bool $orphanRemoval = false,
        public string $fetch = 'lazy',
    ) {
    }
}