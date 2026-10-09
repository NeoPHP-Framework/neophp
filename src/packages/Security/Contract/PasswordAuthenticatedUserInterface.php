<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Contract;

interface PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string;
}