<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Exception;

use Throwable;

class CustomUserMessageAuthenticationException extends AuthenticationException
{
    public function __construct(string $message = '', array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous, $context);

        $this->safeMessage = $message;
    }
}