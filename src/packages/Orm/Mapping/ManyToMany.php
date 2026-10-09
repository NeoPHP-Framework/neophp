<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Mapping;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class ManyToMany
{
    public function __construct(
        public string $target,
        public ?string $mappedBy = null,
        public ?string $inversedBy = null,
        public ?string $joinTable = null,
        public ?string $joinColumn = null,
        public ?string $inverseJoinColumn = null,
        public array $cascade = [],
        public array $orderBy = [],
    ) {
    }
}