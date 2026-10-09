<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Exception;

use Throwable;

class AccessDeniedException extends SecurityException
{
    protected int $statusCode = 403;

    protected array $attributes = [];

    protected mixed $subject = null;

    public function __construct(string $message = 'Access Denied.', array $attributes = [], mixed $subject = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous, ['attributes' => implode(', ', array_map('strval', $attributes))]);

        $this->attributes = $attributes;
        $this->subject = $subject;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getSubject(): mixed
    {
        return $this->subject;
    }
}