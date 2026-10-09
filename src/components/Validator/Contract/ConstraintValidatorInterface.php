<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Contract;

use NeoPHP\Component\Validator\Context\ExecutionContext;

interface ConstraintValidatorInterface
{
    public function validate(mixed $value, ConstraintInterface $constraint, ExecutionContext $context): void;
}