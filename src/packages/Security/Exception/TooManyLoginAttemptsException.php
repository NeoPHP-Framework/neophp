<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Exception;

class TooManyLoginAttemptsException extends AuthenticationException
{
    protected int $statusCode = 429;

    protected string $safeMessage = 'Too many failed login attempts, please try again in {minutes} minute(s).';

    public function __construct(int $retryAfter)
    {
        parent::__construct('Too many failed login attempts, please try again in {minutes} minute(s).', 0, null, [
            'retry_after' => $retryAfter,
            'minutes' => max(1, (int) ceil($retryAfter / 60)),
        ]);

        $this->headers = ['Retry-After' => (string) $retryAfter];
    }

    public function getRetryAfter(): int
    {
        return (int) $this->context['retry_after'];
    }
}