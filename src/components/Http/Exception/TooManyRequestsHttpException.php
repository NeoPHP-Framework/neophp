<?php

declare(strict_types=1);

namespace NeoPHP\Component\Http\Exception;

use Throwable;

class TooManyRequestsHttpException extends HttpException
{
    public function __construct(?int $retryAfter = null, string $message = 'Too Many Requests', array $headers = [], array $context = [], ?Throwable $previous = null)
    {
        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) max(0, $retryAfter);
        }

        parent::__construct(429, $message, $headers, $context, $previous);
    }

    public function getRetryAfter(): ?int
    {
        return isset($this->headers['Retry-After']) ? (int) $this->headers['Retry-After'] : null;
    }
}