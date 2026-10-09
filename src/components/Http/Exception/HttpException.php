<?php

declare(strict_types=1);

namespace NeoPHP\Component\Http\Exception;

use NeoPHP\Component\Exception\Exception\FrameworkException;
use Throwable;

class HttpException extends FrameworkException
{
    public function __construct(int $statusCode = 500, string $message = '', array $headers = [], array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous, $context);

        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }
}