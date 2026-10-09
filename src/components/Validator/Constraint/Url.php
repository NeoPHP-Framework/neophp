<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use Attribute;
use NeoPHP\Component\Validator\Contract\AbstractConstraint;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Url extends AbstractConstraint
{
    public string $message = 'This value is not a valid URL.';

    public function __construct(public array $protocols = ['http', 'https'], ?string $message = null, ?array $groups = null)
    {
        $this->message = $message ?? $this->message;
        $this->setGroups($groups);
    }
}