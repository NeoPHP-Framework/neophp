<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Contract;

abstract class AbstractConstraint implements ConstraintInterface
{
    public const DEFAULT_GROUP = 'Default';

    public array $groups = [self::DEFAULT_GROUP];

    public function validatedBy(): string
    {
        return static::class . 'Validator';
    }

    public function getGroups(): array
    {
        return $this->groups;
    }

    public function inGroups(array $groups): bool
    {
        return array_intersect($this->groups, $groups) !== [];
    }

    protected function setGroups(?array $groups): void
    {
        $this->groups = $groups === null || $groups === [] ? [self::DEFAULT_GROUP] : array_values(array_map('strval', $groups));
    }
}