<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;

class EmailValidator extends AbstractConstraintValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, Email::class);

        if ($this->isEmpty($value)) {
            return;
        }

        $string = $this->toString($value);

        if ($string === null || filter_var($string, FILTER_VALIDATE_EMAIL) === false) {
            $context->addViolation($constraint->message, ['value' => static::formatValue($value)]);
        }
    }
}