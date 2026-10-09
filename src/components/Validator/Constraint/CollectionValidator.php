<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use ArrayAccess;
use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;

class CollectionValidator extends AbstractConstraintValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, Collection::class);

        if ($value === null) {
            return;
        }

        if (!is_array($value) && !$value instanceof ArrayAccess) {
            $context->addViolation($constraint->typeMessage, ['value' => static::formatValue($value)]);

            return;
        }

        foreach ($constraint->fields as $field => $constraints) {
            $exists = is_array($value) ? array_key_exists($field, $value) : $value->offsetExists($field);

            if (!$exists) {
                if (!$constraint->allowMissingFields) {
                    $context->addViolation($constraint->missingFieldsMessage, ['field' => (string) $field], '[' . $field . ']', null);
                }

                continue;
            }

            $context->validate($value[$field], $constraints, '[' . $field . ']');
        }

        if (!$constraint->allowExtraFields && is_array($value)) {
            foreach (array_diff_key($value, $constraint->fields) as $field => $item) {
                $context->addViolation($constraint->extraFieldsMessage, ['field' => (string) $field], '[' . $field . ']', $item);
            }
        }
    }
}