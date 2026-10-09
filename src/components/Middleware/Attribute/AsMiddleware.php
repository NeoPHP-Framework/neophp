<?php

declare(strict_types=1);

namespace NeoPHP\Component\Middleware\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class AsMiddleware
{
    public function __construct(
        public ?string $name = null,
        public bool $global = false,
        public int $priority = 0,
    ) {
    }
}