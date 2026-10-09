<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER)]
class Ignore
{
}