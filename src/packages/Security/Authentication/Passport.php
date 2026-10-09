<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Authentication;

use Closure;
use NeoPHP\Package\Security\Contract\UserInterface;

class Passport
{
    public const BADGE_CSRF = 'csrf';

    public const BADGE_REMEMBER_ME = 'remember_me';

    protected ?UserInterface $user = null;

    protected array $checks = [];

    public function __construct(
        protected string $userIdentifier,
        protected ?string $password = null,
        protected ?Closure $userLoader = null,
        protected array $badges = [],
        protected array $attributes = [],
    ) {
    }

    public static function selfValidating(string $userIdentifier, ?Closure $userLoader = null, array $badges = [], array $attributes = []): static
    {
        return new static($userIdentifier, null, $userLoader, $badges, $attributes);
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    public function getUserLoader(): ?Closure
    {
        return $this->userLoader;
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

    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function erasePassword(): static
    {
        $this->password = null;

        return $this;
    }

    public function addCheck(Closure $check): static
    {
        $this->checks[] = $check;

        return $this;
    }

    public function getChecks(): array
    {
        return $this->checks;
    }

    public function csrf(string $id, ?string $token): static
    {
        return $this->setBadge(self::BADGE_CSRF, ['id' => $id, 'token' => $token]);
    }

    public function rememberMe(bool $enabled = true): static
    {
        return $this->setBadge(self::BADGE_REMEMBER_ME, $enabled);
    }

    public function isRememberMe(): bool
    {
        return (bool) $this->getBadge(self::BADGE_REMEMBER_ME, false);
    }

    public function setBadge(string $name, mixed $value): static
    {
        $this->badges[$name] = $value;

        return $this;
    }

    public function hasBadge(string $name): bool
    {
        return array_key_exists($name, $this->badges);
    }

    public function getBadge(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->badges) ? $this->badges[$name] : $default;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function setAttribute(string $name, mixed $value): static
    {
        $this->attributes[$name] = $value;

        return $this;
    }
}