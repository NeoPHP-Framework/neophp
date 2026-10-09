<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Token;

class RememberMeToken extends SecurityToken
{
    public function isRemembered(): bool
    {
        return true;
    }
}