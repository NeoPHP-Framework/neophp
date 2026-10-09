<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\User;

use NeoPHP\Package\Orm\OrmManagerInterface;
use NeoPHP\Package\Security\Contract\PasswordAuthenticatedUserInterface;
use NeoPHP\Package\Security\Contract\PasswordUpgraderInterface;
use NeoPHP\Package\Security\Contract\UserInterface;
use NeoPHP\Package\Security\Contract\UserProviderInterface;
use NeoPHP\Package\Security\Exception\SecurityException;
use NeoPHP\Package\Security\Exception\UserNotFoundException;

class EntityUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(protected OrmManagerInterface $orm, protected string $class, protected ?string $property = null)
    {
        if (!is_a($class, UserInterface::class, true)) {
            throw new SecurityException('The entity "{class}" used by the entity user provider must implement {interface}.', 0, null, ['class' => $class, 'interface' => UserInterface::class]);
        }
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $repository = $this->orm->getRepository($this->class);

        if ($this->property !== null) {
            $user = $repository->findOneBy([$this->property => $identifier]);
        } elseif ($repository instanceof UserProviderInterface || method_exists($repository, 'loadUserByIdentifier')) {
            $user = $repository->loadUserByIdentifier($identifier);
        } else {
            throw new SecurityException('The entity user provider of "{class}" needs a "property" option or a repository with a loadUserByIdentifier() method.', 0, null, ['class' => $this->class]);
        }

        if (!$user instanceof UserInterface) {
            throw new UserNotFoundException('The user "{identifier}" does not exist.', 0, null, ['identifier' => $identifier]);
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$this->supportsClass(UserClass::of($user))) {
            throw new SecurityException('Instances of "{class}" are not supported by the entity user provider of "{entity}".', 0, null, ['class' => UserClass::of($user), 'entity' => $this->class]);
        }

        $id = $this->orm->getMetadata($user)->getIdentifierValue($user);
        $refreshed = $id === null ? null : $this->orm->find($this->class, $id);

        if (!$refreshed instanceof UserInterface) {
            throw new UserNotFoundException('The user "{identifier}" does not exist anymore.', 0, null, ['identifier' => $user->getUserIdentifier()]);
        }

        return $refreshed;
    }

    public function supportsClass(string $class): bool
    {
        return is_a($class, $this->class, true);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $hashedPassword): void
    {
        if (!$this->supportsClass(UserClass::of($user)) || !method_exists($user, 'setPassword')) {
            return;
        }

        $user->setPassword($hashedPassword);
        $this->orm->persist($user);
        $this->orm->flush();
    }
}