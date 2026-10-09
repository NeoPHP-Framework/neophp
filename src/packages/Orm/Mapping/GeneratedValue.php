<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Mapping;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class GeneratedValue
{
    public const AUTO = 'auto';

    public const UUID = 'uuid';

    public const NONE = 'none';

    public function __construct(public string $strategy = self::AUTO)
    {
    }
}