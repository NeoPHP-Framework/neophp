<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class AsMessageHandler
{
    public function __construct(
        public ?string $handles = null,
        public ?string $method = null,
        public int $priority = 0,
    ) {
    }
}