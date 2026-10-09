<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use Attribute;
use NeoPHP\Component\Validator\Contract\AbstractConstraint;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class NotBlank extends AbstractConstraint
{
    public string $message = 'This value should not be blank.';

    public function __construct(?string $message = null, public bool $allowNull = false, public bool $trim = false, ?array $groups = null)
    {
        $this->message = $message ?? $this->message;
        $this->setGroups($groups);
    }
}