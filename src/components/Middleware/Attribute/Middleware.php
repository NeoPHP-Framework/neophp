<?php

declare(strict_types=1);

namespace NeoPHP\Component\Middleware\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Middleware
{
    public array $middlewares;

    public function __construct(string ...$middlewares)
    {
        $this->middlewares = $middlewares;
    }
}