<?php

declare(strict_types=1);

namespace NeoPHP\Component\Container\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_CLASS)]
class Autowire
{
    public function __construct(
        public mixed $value = null,
        public ?string $service = null,
        public ?string $config = null,
        public ?string $env = null,
        public ?string $param = null,
        public ?bool $shared = null,
    ) {
    }
}