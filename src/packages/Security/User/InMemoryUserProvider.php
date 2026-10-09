<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\User;

use NeoPHP\Package\Security\Contract\UserInterface;
use NeoPHP\Package\Security\Contract\UserProviderInterface;
use NeoPHP\Package\Security\Exception\SecurityException;
use NeoPHP\Package\Security\Exception\UserNotFoundException;

class InMemoryUserProvider implements UserProviderInterface
{
    protected array $users = [];

    public function __construct(array $users = [])
    {
        foreach ($users as $identifier => $user) {
            $user = is_array($user) ? $user : ['password' => $user];
            $this->createUser(new InMemoryUser((string) $identifier, isset($user['password']) ? (string) $user['password'] : null, array_values(array_map('strval', (array) ($user['roles'] ?? [])))));
        }
    }

    public function createUser(InMemoryUser $user): static
    {
        $identifier = strtolower($user->getUserIdentifier());

        if (isset($this->users[$identifier])) {
            throw new SecurityException('The user "{identifier}" already exists in the memory provider.', 0, null, ['identifier' => $user->getUserIdentifier()]);
        }

        $this->users[$identifier] = $user;

        return $this;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->users[strtolower($identifier)] ?? null;

        if ($user === null) {
            throw new UserNotFoundException('The user "{identifier}" does not exist.', 0, null, ['identifier' => $identifier]);
        }

        return new InMemoryUser($user->getUserIdentifier(), $user->getPassword(), $user->getRoles());
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof InMemoryUser) {
            throw new SecurityException('Instances of "{class}" are not supported by the memory provider.', 0, null, ['class' => $user::class]);
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return is_a($class, InMemoryUser::class, true);
    }
}