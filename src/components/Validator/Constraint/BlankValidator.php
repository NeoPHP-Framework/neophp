<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;

class BlankValidator extends AbstractConstraintValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, Blank::class);

        if ($value !== null && $value !== '' && $value !== [] && $value !== false) {
            $context->addViolation($constraint->message, ['value' => static::formatValue($value)]);
        }
    }
}