<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
class Context
{
    public function __construct(public array $context = [], public array $normalization = [], public array $denormalization = [], public array $groups = [])
    {
    }
}