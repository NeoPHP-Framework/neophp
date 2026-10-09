<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Mapping;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class OneToMany
{
    public function __construct(
        public string $target,
        public string $mappedBy,
        public array $cascade = [],
        public bool $orphanRemoval = false,
        public array $orderBy = [],
    ) {
    }
}