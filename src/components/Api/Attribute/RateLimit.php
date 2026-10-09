<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class RateLimit
{
    public function __construct(
        public string $limiter,
        public string $key = 'ip',
        public int $cost = 1,
        public array $methods = [],
    ) {
    }
}