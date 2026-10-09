<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class GreaterThan extends AbstractComparison
{
    public string $message = 'This value should be greater than {{ compared_value }}.';

    public function compare(mixed $value, mixed $compared): bool
    {
        return $value > $compared;
    }
}