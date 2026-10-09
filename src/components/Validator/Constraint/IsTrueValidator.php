<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;

class IsTrueValidator extends AbstractConstraintValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, IsTrue::class);

        if ($value !== null && $value !== true && $value !== 1 && $value !== '1') {
            $context->addViolation($constraint->message, ['value' => static::formatValue($value)]);
        }
    }
}