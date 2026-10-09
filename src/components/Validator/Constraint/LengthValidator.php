<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;

class LengthValidator extends AbstractConstraintValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, Length::class);

        if ($this->isEmpty($value)) {
            return;
        }

        $string = $this->toString($value);

        if ($string === null) {
            $context->addViolation($constraint->typeMessage, ['value' => static::formatValue($value)]);

            return;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($string, 'UTF-8') : strlen($string);
        $parameters = ['value' => static::formatValue($string), 'length' => $length];

        if ($constraint->min !== null && $constraint->min === $constraint->max && $length !== $constraint->min) {
            $context->addViolation($constraint->exactMessage, $parameters + ['limit' => $constraint->min]);
        } elseif ($constraint->min !== null && $length < $constraint->min) {
            $context->addViolation($constraint->minMessage, $parameters + ['limit' => $constraint->min]);
        } elseif ($constraint->max !== null && $length > $constraint->max) {
            $context->addViolation($constraint->maxMessage, $parameters + ['limit' => $constraint->max]);
        }
    }
}