<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Contract;

interface UserProviderInterface
{
    public function loadUserByIdentifier(string $identifier): UserInterface;

    public function refreshUser(UserInterface $user): UserInterface;

    public function supportsClass(string $class): bool;
}