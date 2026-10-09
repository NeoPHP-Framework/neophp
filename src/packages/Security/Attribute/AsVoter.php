<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class AsVoter
{
    public function __construct(public int $priority = 0)
    {
    }
}