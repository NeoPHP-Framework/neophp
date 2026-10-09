<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\User;

use NeoPHP\Package\Security\Contract\PasswordAuthenticatedUserInterface;
use NeoPHP\Package\Security\Contract\UserInterface;
use Stringable;

class InMemoryUser implements UserInterface, PasswordAuthenticatedUserInterface, Stringable
{
    public function __construct(protected string $identifier, protected ?string $password = null, protected array $roles = [])
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function __toString(): string
    {
        return $this->identifier;
    }
}