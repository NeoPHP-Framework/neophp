<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;

class IsFalseValidator extends AbstractConstraintValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, IsFalse::class);

        if ($value !== null && $value !== false && $value !== 0 && $value !== '0') {
            $context->addViolation($constraint->message, ['value' => static::formatValue($value)]);
        }
    }
}