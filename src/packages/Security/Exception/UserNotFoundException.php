<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Exception;

class UserNotFoundException extends AuthenticationException
{
    protected string $safeMessage = 'Invalid credentials.';
}