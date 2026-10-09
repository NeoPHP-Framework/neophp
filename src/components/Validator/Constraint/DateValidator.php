<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;

class DateValidator extends AbstractConstraintValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, Date::class);

        if ($this->isEmpty($value)) {
            return;
        }

        $string = $this->toString($value);

        if ($string === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $string, $matches) !== 1 || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            $context->addViolation($constraint->message, ['value' => static::formatValue($value)]);
        }
    }
}