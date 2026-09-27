<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
class MapQueryString
{
    public function __construct(public ?array $groups = null, public ?array $validationGroups = null, public array $context = [], public bool $validate = true)
    {
    }
}