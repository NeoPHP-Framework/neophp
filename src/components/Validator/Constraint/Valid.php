<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use Attribute;
use NeoPHP\Component\Validator\Contract\AbstractConstraint;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Valid extends AbstractConstraint
{
    public function __construct(public bool $traverse = true, ?array $groups = null)
    {
        $this->setGroups($groups);
    }
}