<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Token;

use NeoPHP\Package\Security\Contract\TokenInterface;
use NeoPHP\Package\Security\Contract\UserInterface;

class SecurityToken implements TokenInterface
{
    public function __construct(
        protected UserInterface $user,
        protected ?string $firewall = null,
        protected ?string $authenticator = null,
        protected array $attributes = [],
    ) {
    }

    public function getUser(): ?UserInterface
    {
        return $this->user;
    }

    public function setUser(UserInterface $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getUserIdentifier(): ?string
    {
        return $this->user->getUserIdentifier();
    }

    public function getRoleNames(): array
    {
        return array_values(array_unique(array_map('strval', $this->user->getRoles())));
    }

    public function getFirewall(): ?string
    {
        return $this->firewall;
    }

    public function getAuthenticator(): ?string
    {
        return $this->authenticator;
    }

    public function isAuthenticated(): bool
    {
        return true;
    }

    public function isRemembered(): bool
    {
        return false;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    public function setAttribute(string $name, mixed $value): static
    {
        $this->attributes[$name] = $value;

        return $this;
    }
}