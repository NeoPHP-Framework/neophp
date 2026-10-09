<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Exception;

class InvalidCsrfTokenException extends AuthenticationException
{
    protected string $safeMessage = 'Invalid CSRF token.';
}