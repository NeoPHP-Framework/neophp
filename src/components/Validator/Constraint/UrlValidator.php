<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;

class UrlValidator extends AbstractConstraintValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, Url::class);

        if ($this->isEmpty($value)) {
            return;
        }

        $string = $this->toString($value);
        $scheme = $string === null ? null : strtolower((string) parse_url($string, PHP_URL_SCHEME));

        if ($string === null || filter_var($string, FILTER_VALIDATE_URL) === false || !in_array($scheme, array_map('strtolower', $constraint->protocols), true)) {
            $context->addViolation($constraint->message, ['value' => static::formatValue($value)]);
        }
    }
}