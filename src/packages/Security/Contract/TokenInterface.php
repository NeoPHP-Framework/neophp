<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Contract;

interface TokenInterface
{
    public function getUser(): ?UserInterface;

    public function setUser(UserInterface $user): static;

    public function getUserIdentifier(): ?string;

    public function getRoleNames(): array;

    public function getFirewall(): ?string;

    public function getAuthenticator(): ?string;

    public function isAuthenticated(): bool;

    public function isRemembered(): bool;

    public function getAttributes(): array;

    public function getAttribute(string $name, mixed $default = null): mixed;

    public function setAttribute(string $name, mixed $value): static;
}