<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class MapPagination
{
    public function __construct(public ?int $defaultLimit = null, public ?int $maxLimit = null)
    {
    }
}