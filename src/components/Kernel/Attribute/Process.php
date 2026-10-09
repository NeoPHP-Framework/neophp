<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Process extends AbstractModule
{
    public const TYPE = 'process';
}