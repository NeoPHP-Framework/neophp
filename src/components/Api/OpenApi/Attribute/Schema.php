<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\OpenApi\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Schema
{
    public function __construct(public ?string $name = null, public ?string $description = null, public mixed $example = null)
    {
    }
}