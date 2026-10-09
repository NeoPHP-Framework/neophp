<?php

declare(strict_types=1);

namespace NeoPHP\Component\Container\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Inject
{
    public function __construct(
        public ?string $service = null,
        public ?string $config = null,
        public ?string $env = null,
        public ?string $param = null,
        public mixed $value = null,
    ) {
    }
}