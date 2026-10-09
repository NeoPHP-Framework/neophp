<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use Attribute;
use NeoPHP\Component\Validator\Contract\AbstractConstraint;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Regex extends AbstractConstraint
{
    public string $message = 'This value is not valid.';

    public function __construct(public string $pattern, public bool $match = true, ?string $message = null, ?array $groups = null)
    {
        $this->message = $message ?? $this->message;
        $this->setGroups($groups);
    }
}