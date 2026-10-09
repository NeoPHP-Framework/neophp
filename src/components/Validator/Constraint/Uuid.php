<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use Attribute;
use NeoPHP\Component\Validator\Contract\AbstractConstraint;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Uuid extends AbstractConstraint
{
    public string $message = 'This value is not a valid UUID.';

    public function __construct(?string $message = null, ?array $groups = null)
    {
        $this->message = $message ?? $this->message;
        $this->setGroups($groups);
    }
}