<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Hasher;

use NeoPHP\Package\Security\Contract\PasswordAuthenticatedUserInterface;
use NeoPHP\Package\Security\Contract\PasswordHasherInterface;
use NeoPHP\Package\Security\Exception\SecurityException;

class UserPasswordHasher
{
    public const DEFAULT_KEY = 'default';

    protected array $hashers = [];

    public function __construct(protected array $config = [])
    {
    }

    public function getPasswordHasher(string|object $user = self::DEFAULT_KEY): PasswordHasherInterface
    {
        $key = $this->resolveKey(is_object($user) ? $user::class : $user);

        return $this->hashers[$key] ??= $this->create($this->config[$key] ?? 'auto');
    }

    public function hashPassword(PasswordAuthenticatedUserInterface|string $user, string $plainPassword): string
    {
        return $this->getPasswordHasher($user)->hash($plainPassword);
    }

    public function isPasswordValid(PasswordAuthenticatedUserInterface $user, string $plainPassword): bool
    {
        $hashed = $user->getPassword();

        return $hashed !== null && $this->getPasswordHasher($user)->verify($hashed, $plainPassword);
    }

    public function needsRehash(PasswordAuthenticatedUserInterface $user): bool
    {
        $hashed = $user->getPassword();

        return $hashed !== null && $this->getPasswordHasher($user)->needsRehash($hashed);
    }

    protected function resolveKey(string $class): string
    {
        if (isset($this->config[$class])) {
            return $class;
        }

        if (class_exists($class) || interface_exists($class)) {
            foreach ([...array_values(class_parents($class) ?: []), ...array_values(class_implements($class) ?: [])] as $parent) {
                if (isset($this->config[$parent])) {
                    return $parent;
                }
            }
        }

        return self::DEFAULT_KEY;
    }

    protected function create(mixed $config): PasswordHasherInterface
    {
        $config = is_array($config) ? $config : ['algorithm' => (string) $config];

        if (isset($config['id'])) {
            $class = (string) $config['id'];

            if (!is_a($class, PasswordHasherInterface::class, true)) {
                throw new SecurityException('The password hasher "{class}" must implement {interface}.', 0, null, ['class' => $class, 'interface' => PasswordHasherInterface::class]);
            }

            return new $class();
        }

        $algorithm = strtolower((string) ($config['algorithm'] ?? 'auto'));

        if ($algorithm === 'plaintext') {
            return new PlaintextPasswordHasher();
        }

        return new NativePasswordHasher(
            $algorithm,
            isset($config['cost']) ? (int) $config['cost'] : null,
            isset($config['memory_cost']) ? (int) $config['memory_cost'] : null,
            isset($config['time_cost']) ? (int) $config['time_cost'] : null,
        );
    }
}