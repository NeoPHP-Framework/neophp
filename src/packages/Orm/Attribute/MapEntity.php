<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class MapEntity
{
    public function __construct(
        public ?string $id = null,
        public array $mapping = [],
        public bool $disabled = false,
        public ?string $message = null,
    ) {
    }
}