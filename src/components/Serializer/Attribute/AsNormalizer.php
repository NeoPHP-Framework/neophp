<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class AsNormalizer
{
    public function __construct(public int $priority = 0)
    {
    }
}