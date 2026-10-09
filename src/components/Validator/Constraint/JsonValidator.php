<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;

class JsonValidator extends AbstractConstraintValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, Json::class);

        if ($this->isEmpty($value)) {
            return;
        }

        $string = $this->toString($value);

        if ($string !== null) {
            json_decode($string);
        }

        if ($string === null || json_last_error() !== JSON_ERROR_NONE) {
            $context->addViolation($constraint->message, ['value' => static::formatValue($value)]);
        }
    }
}