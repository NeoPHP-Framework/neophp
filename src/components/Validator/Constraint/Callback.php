<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use Attribute;
use NeoPHP\Component\Validator\Contract\AbstractConstraint;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Callback extends AbstractConstraint
{
    public mixed $callback;

    public function __construct(string|array|callable $callback, ?array $groups = null)
    {
        $this->callback = $callback;
        $this->setGroups($groups);
    }
}