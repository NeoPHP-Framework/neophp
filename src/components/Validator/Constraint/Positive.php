<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Positive extends GreaterThan
{
    public string $message = 'This value should be positive.';

    public function __construct(?string $message = null, ?array $groups = null)
    {
        parent::__construct(0, null, $message, $groups);
    }
}