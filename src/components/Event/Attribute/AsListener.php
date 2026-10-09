<?php

declare(strict_types=1);

namespace NeoPHP\Component\Event\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class AsListener
{
    public function __construct(
        public ?string $event = null,
        public ?string $method = null,
        public int $priority = 0,
    ) {
    }
}