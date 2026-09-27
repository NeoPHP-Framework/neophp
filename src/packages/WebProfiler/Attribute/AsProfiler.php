<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class AsProfiler
{
    public function __construct(public ?int $priority = null)
    {
    }
}