<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;

class ValidValidator extends AbstractConstraintValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, Valid::class);

        if (is_object($value) && !is_iterable($value)) {
            $context->validateObject($value);

            return;
        }

        if ($constraint->traverse && is_iterable($value)) {
            foreach ($value as $key => $item) {
                if (is_object($item)) {
                    $context->validateObject($item, '[' . $key . ']');
                }
            }
        }
    }
}