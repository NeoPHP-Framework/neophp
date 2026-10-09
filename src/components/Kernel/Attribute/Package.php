<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Package extends AbstractModule
{
    public const TYPE = 'package';
}