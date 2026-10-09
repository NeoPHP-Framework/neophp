<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;

class AllValidator extends AbstractConstraintValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, All::class);

        if ($value === null) {
            return;
        }

        if (!is_iterable($value)) {
            $context->addViolation($constraint->typeMessage, ['value' => static::formatValue($value)]);

            return;
        }

        foreach ($value as $key => $item) {
            $context->validate($item, $constraint->constraints, '[' . $key . ']');
        }
    }
}