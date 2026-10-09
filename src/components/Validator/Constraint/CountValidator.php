<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use Countable;
use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;

class CountValidator extends AbstractConstraintValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, Count::class);

        if ($value === null) {
            return;
        }

        if (!is_array($value) && !$value instanceof Countable) {
            $context->addViolation($constraint->typeMessage, ['value' => static::formatValue($value)]);

            return;
        }

        $count = count($value);

        if ($constraint->min !== null && $constraint->min === $constraint->max && $count !== $constraint->min) {
            $context->addViolation($constraint->exactMessage, ['count' => $count, 'limit' => $constraint->min]);
        } elseif ($constraint->min !== null && $count < $constraint->min) {
            $context->addViolation($constraint->minMessage, ['count' => $count, 'limit' => $constraint->min]);
        } elseif ($constraint->max !== null && $count > $constraint->max) {
            $context->addViolation($constraint->maxMessage, ['count' => $count, 'limit' => $constraint->max]);
        }
    }
}