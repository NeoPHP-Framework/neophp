<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Contract;

interface PasswordUpgraderInterface
{
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $hashedPassword): void;
}