<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use NeoPHP\Component\Validator\Context\ExecutionContext;
use NeoPHP\Component\Validator\Contract\AbstractConstraintValidator;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;

class IpValidator extends AbstractConstraintValidator
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void
    {
        $this->expect($constraint, Ip::class);

        if ($this->isEmpty($value)) {
            return;
        }

        $flags = match ($constraint->version) {
            '4' => FILTER_FLAG_IPV4,
            '6' => FILTER_FLAG_IPV6,
            default => 0,
        };
        $string = $this->toString($value);

        if ($string === null || filter_var($string, FILTER_VALIDATE_IP, $flags) === false) {
            $context->addViolation($constraint->message, ['value' => static::formatValue($value)]);
        }
    }
}