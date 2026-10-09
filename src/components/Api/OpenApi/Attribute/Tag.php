<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\OpenApi\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Tag
{
    public function __construct(public string $name, public ?string $description = null)
    {
    }
}