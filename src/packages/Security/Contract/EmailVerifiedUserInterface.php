<?php

namespace NeoPHP\Package\Security\Contract;

interface EmailVerifiedUserInterface
{
    public function isEmailVerified(): bool;
}