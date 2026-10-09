<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use DateTimeImmutable;
use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;

class DateTimeValidator extends AbstractConstraintValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, DateTime::class);

        if ($this->isEmpty($value)) {
            return;
        }

        $string = $this->toString($value);
        $date = $string === null ? false : DateTimeImmutable::createFromFormat('!' . $constraint->format, $string);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format($constraint->format) !== $string) {
            $context->addViolation($constraint->message, ['value' => static::formatValue($value), 'format' => $constraint->format]);
        }
    }
}