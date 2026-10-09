<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Exception;

class AuthenticationException extends SecurityException
{
    protected int $statusCode = 401;

    protected string $safeMessage = 'An authentication exception occurred.';

    protected ?string $translatedSafeMessage = null;

    public function getSafeMessage(): string
    {
        return $this->translatedSafeMessage ?? static::interpolate($this->safeMessage, $this->context);
    }

    public function getSafeMessageKey(): string
    {
        return $this->safeMessage;
    }

    public function getSafeMessageParameters(): array
    {
        return array_filter($this->context, static fn (mixed $value): bool => is_scalar($value) || $value === null);
    }

    public function setTranslatedSafeMessage(?string $message): static
    {
        $this->translatedSafeMessage = $message;

        return $this;
    }
}