<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;
use NeoPHP\Component\Validator\Exception\ValidatorException;

class RegexValidator extends AbstractConstraintValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, Regex::class);

        if ($this->isEmpty($value)) {
            return;
        }

        $string = $this->toString($value);
        $result = $string === null ? false : @preg_match($constraint->pattern, $string);

        if ($result === false && $string !== null) {
            throw new ValidatorException('The pattern "{pattern}" of the Regex constraint is not valid.', 0, null, ['pattern' => $constraint->pattern]);
        }

        if ($string === null || ($result === 1) !== $constraint->match) {
            $context->addViolation($constraint->message, ['value' => static::formatValue($value), 'pattern' => $constraint->pattern]);
        }
    }
}