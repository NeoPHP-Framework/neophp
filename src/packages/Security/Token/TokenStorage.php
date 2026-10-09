<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Token;

use Closure;
use NeoPHP\Package\Security\Contract\TokenInterface;

class TokenStorage
{
    protected ?TokenInterface $token = null;

    protected ?Closure $initializer = null;

    public function getToken(): ?TokenInterface
    {
        if ($this->initializer !== null) {
            $initializer = $this->initializer;
            $this->initializer = null;
            $this->token = $initializer();
        }

        return $this->token;
    }

    public function setToken(?TokenInterface $token): void
    {
        $this->initializer = null;
        $this->token = $token;
    }

    public function setInitializer(?Closure $initializer): void
    {
        $this->initializer = $initializer;
    }

    public function isInitialized(): bool
    {
        return $this->initializer === null;
    }

    public function reset(): void
    {
        $this->initializer = null;
        $this->token = null;
    }
}