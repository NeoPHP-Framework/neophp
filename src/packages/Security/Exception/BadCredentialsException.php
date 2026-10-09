<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Exception;

class BadCredentialsException extends AuthenticationException
{
    protected string $safeMessage = 'Invalid credentials.';
}