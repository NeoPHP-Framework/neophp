<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator;

use NeoPHP\Component\Validator\Contract\AbstractConstraint;
use NeoPHP\Component\Validator\Contract\ConstraintInterface;
use NeoPHP\Component\Validator\Exception\ValidationFailedException;
use NeoPHP\Component\Validator\Exception\ValidatorException;
use NeoPHP\Component\Validator\Violation\ViolationList;

interface ValidatorManagerInterface
{
    /**
     * Validates a value: an object with the constraints of its attributes, or a value with the given constraints.
     *
     * @param mixed $value The value or the object
     * @param ConstraintInterface|array<mixed>|null $constraints A constraint, a list of constraints, a map of field => constraints for an array, or null to use the attributes of the object
     * @param array<string> $groups Validation groups
     * @return ViolationList The violations, empty when the value is valid
     * @throws ValidatorException When no constraint is given for a value that is not an object, a constraint is invalid or its validator does not exist
     */
    public function validate(mixed $value, ConstraintInterface|array|null $constraints = null, array $groups = [AbstractConstraint::DEFAULT_GROUP]): ViolationList;

    /**
     * Validates one property of an object with the constraints of its attributes.
     *
     * @param object $object The object
     * @param string $property Name of the property
     * @param array<string> $groups Validation groups
     * @return ViolationList The violations of the property
     * @throws ValidatorException When a constraint is invalid or its validator does not exist
     */
    public function validateProperty(object $object, string $property, array $groups = [AbstractConstraint::DEFAULT_GROUP]): ViolationList;

    /**
     * Validates a value and throws when it is not valid.
     *
     * @param mixed $value The value or the object
     * @param ConstraintInterface|array<mixed>|null $constraints A constraint, a list of constraints, a map of field => constraints for an array, or null to use the attributes of the object
     * @param array<string> $groups Validation groups
     * @return void
     * @throws ValidationFailedException When the value is not valid (rendered as a 422 response)
     * @throws ValidatorException When no constraint is given for a value that is not an object, a constraint is invalid or its validator does not exist
     */
    public function validateOrFail(mixed $value, ConstraintInterface|array|null $constraints = null, array $groups = [AbstractConstraint::DEFAULT_GROUP]): void;
}