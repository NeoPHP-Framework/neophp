<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;

class UuidValidator extends AbstractConstraintValidator
{
    public const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, Uuid::class);

        if ($this->isEmpty($value)) {
            return;
        }

        $string = $this->toString($value);

        if ($string === null || preg_match(static::PATTERN, $string) !== 1) {
            $context->addViolation($constraint->message, ['value' => static::formatValue($value)]);
        }
    }
}