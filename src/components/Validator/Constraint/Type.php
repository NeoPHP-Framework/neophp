<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use Attribute;
use NeoPHP\Component\Validator\Contract\AbstractConstraint;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Type extends AbstractConstraint
{
    public string $message = 'This value should be of type {{ type }}.';

    public array $types;

    public function __construct(string|array $type, ?string $message = null, ?array $groups = null)
    {
        $this->types = (array) $type;
        $this->message = $message ?? $this->message;
        $this->setGroups($groups);
    }
}