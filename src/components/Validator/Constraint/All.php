<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use Attribute;
use NeoPHP\Component\Validator\Contract\AbstractConstraint;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class All extends AbstractConstraint
{
    public string $typeMessage = 'This value should be a collection.';

    public array $constraints;

    public function __construct(array|AbstractConstraint $constraints, ?array $groups = null)
    {
        $this->constraints = is_array($constraints) ? $constraints : [$constraints];
        $this->setGroups($groups);
    }
}