<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Token;

use NeoPHP\Package\Security\Contract\TokenInterface;
use NeoPHP\Package\Security\Contract\UserInterface;
use NeoPHP\Package\Security\Exception\SecurityException;

class NullToken implements TokenInterface
{
    public function __construct(protected ?string $firewall = null)
    {
    }

    public function getUser(): ?UserInterface
    {
        return null;
    }

    public function setUser(UserInterface $user): static
    {
        throw new SecurityException('A user cannot be set on a NullToken.');
    }

    public function getUserIdentifier(): ?string
    {
        return null;
    }

    public function getRoleNames(): array
    {
        return [];
    }

    public function getFirewall(): ?string
    {
        return $this->firewall;
    }

    public function getAuthenticator(): ?string
    {
        return null;
    }

    public function isAuthenticated(): bool
    {
        return false;
    }

    public function isRemembered(): bool
    {
        return false;
    }

    public function getAttributes(): array
    {
        return [];
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $default;
    }

    public function setAttribute(string $name, mixed $value): static
    {
        return $this;
    }
}