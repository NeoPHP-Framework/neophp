<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\User;

use NeoPHP\Package\Security\Contract\PasswordAuthenticatedUserInterface;
use NeoPHP\Package\Security\Contract\PasswordUpgraderInterface;
use NeoPHP\Package\Security\Contract\UserInterface;
use NeoPHP\Package\Security\Contract\UserProviderInterface;
use NeoPHP\Package\Security\Exception\SecurityException;
use NeoPHP\Package\Security\Exception\UserNotFoundException;

class ChainUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(protected array $providers = [])
    {
    }

    public function getProviders(): array
    {
        return $this->providers;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        foreach ($this->providers as $provider) {
            try {
                return $provider->loadUserByIdentifier($identifier);
            } catch (UserNotFoundException) {
            }
        }

        throw new UserNotFoundException('The user "{identifier}" does not exist.', 0, null, ['identifier' => $identifier]);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supportsClass(UserClass::of($user))) {
                return $provider->refreshUser($user);
            }
        }

        throw new SecurityException('No user provider supports the class "{class}".', 0, null, ['class' => UserClass::of($user)]);
    }

    public function supportsClass(string $class): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->supportsClass($class)) {
                return true;
            }
        }

        return false;
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $hashedPassword): void
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof UserProviderInterface && $provider instanceof PasswordUpgraderInterface && $provider->supportsClass(UserClass::of($user))) {
                $provider->upgradePassword($user, $hashedPassword);

                return;
            }
        }
    }
}