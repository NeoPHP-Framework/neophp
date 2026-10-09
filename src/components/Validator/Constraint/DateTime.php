<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use Attribute;
use NeoPHP\Component\Validator\Contract\AbstractConstraint;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class DateTime extends AbstractConstraint
{
    public string $message = 'This value is not a valid datetime.';

    public function __construct(public string $format = 'Y-m-d H:i:s', ?string $message = null, ?array $groups = null)
    {
        $this->message = $message ?? $this->message;
        $this->setGroups($groups);
    }
}