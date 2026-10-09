<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;

class TypeValidator extends AbstractConstraintValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, Type::class);

        if ($value === null) {
            return;
        }

        foreach ($constraint->types as $type) {
            if ($this->matches($value, (string) $type)) {
                return;
            }
        }

        $context->addViolation($constraint->message, ['value' => static::formatValue($value), 'type' => implode('|', $constraint->types)]);
    }

    protected function matches(mixed $value, string $type): bool
    {
        return match (strtolower($type)) {
            'string' => is_string($value),
            'int', 'integer' => is_int($value),
            'float', 'double' => is_float($value),
            'bool', 'boolean' => is_bool($value),
            'array' => is_array($value),
            'numeric' => is_numeric($value),
            'scalar' => is_scalar($value),
            'iterable' => is_iterable($value),
            'callable' => is_callable($value),
            'object' => is_object($value),
            'alpha' => is_string($value) && preg_match('/^[a-z]+$/i', $value) === 1,
            'digit' => is_string($value) && preg_match('/^\d+$/', $value) === 1,
            'alnum' => is_string($value) && preg_match('/^[a-z0-9]+$/i', $value) === 1,
            default => $value instanceof $type,
        };
    }
}