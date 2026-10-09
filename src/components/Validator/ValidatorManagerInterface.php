<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator;

use NeoPHP\Component\Validator\Contract\AbstractConstraint;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;
use NeoPHP\Component\Validator\Violation\ViolationList;

interface ValidatorManagerInterface
{
    public function validate(mixed $value, ConstraintInterface|array|null $constraints = null, array $groups = [AbstractConstraint::DEFAULT_GROUP]): ViolationList;

    public function validateProperty(object $object, string $property, array $groups = [AbstractConstraint::DEFAULT_GROUP]): ViolationList;

    public function validateOrFail(mixed $value, ConstraintInterface|array|null $constraints = null, array $groups = [AbstractConstraint::DEFAULT_GROUP]): void;
}