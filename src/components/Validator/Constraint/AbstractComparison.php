<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Constraint;

use NeoPHP\Component\Validator\Contract\AbstractConstraint;
use NeoPHP\Component\Validator\Exception\ValidatorException;

abstract class AbstractComparison extends AbstractConstraint
{
    public string $message = '';

    public function __construct(public mixed $value = null, public ?string $propertyPath = null, ?string $message = null, ?array $groups = null)
    {
        if ($value === null && $propertyPath === null) {
            throw new ValidatorException('The {constraint} constraint needs a "value" or a "propertyPath".', 0, null, ['constraint' => static::class]);
        }

        $this->message = $message ?? $this->message;
        $this->setGroups($groups);
    }

    public function validatedBy(): string
    {
        return ComparisonValidator::class;
    }

    abstract public function compare(mixed $value, mixed $compared): bool;
}