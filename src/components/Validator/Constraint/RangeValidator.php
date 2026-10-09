<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use DateTimeImmutable;
use DateTimeInterface;
use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;
use Throwable;

class RangeValidator extends AbstractConstraintValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, Range::class);

        if ($this->isEmpty($value)) {
            return;
        }

        $date = $value instanceof DateTimeInterface;

        if (!$date && !is_numeric($value)) {
            $context->addViolation($constraint->invalidMessage, ['value' => static::formatValue($value)]);

            return;
        }

        $min = $this->bound($constraint->min, $date);
        $max = $this->bound($constraint->max, $date);
        $parameters = ['value' => static::formatValue($value), 'min' => (string) $constraint->min, 'max' => (string) $constraint->max];

        if ($min !== null && $max !== null && ($value < $min || $value > $max)) {
            $context->addViolation($constraint->notInRangeMessage, $parameters);
        } elseif ($min !== null && $value < $min) {
            $context->addViolation($constraint->minMessage, $parameters + ['limit' => (string) $constraint->min]);
        } elseif ($max !== null && $value > $max) {
            $context->addViolation($constraint->maxMessage, $parameters + ['limit' => (string) $constraint->max]);
        }
    }

    protected function bound(int|float|string|null $bound, bool $date): mixed
    {
        if ($bound === null || !$date) {
            return $bound;
        }

        try {
            return new DateTimeImmutable((string) $bound);
        } catch (Throwable) {
            return null;
        }
    }
}