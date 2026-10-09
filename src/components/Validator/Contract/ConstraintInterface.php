<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Contract;

interface ConstraintInterface
{
    public function validatedBy(): string;

    public function getGroups(): array;

    public function inGroups(array $groups): bool;
}