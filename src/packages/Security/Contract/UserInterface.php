<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Contract;

interface UserInterface
{
    public function getUserIdentifier(): string;

    public function getRoles(): array;
}