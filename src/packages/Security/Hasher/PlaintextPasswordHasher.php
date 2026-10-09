<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Hasher;

use NeoPHP\Package\Security\Contract\PasswordHasherInterface;

class PlaintextPasswordHasher implements PasswordHasherInterface
{
    public function hash(string $plainPassword): string
    {
        return $plainPassword;
    }

    public function verify(string $hashedPassword, string $plainPassword): bool
    {
        return $hashedPassword !== '' && strlen($plainPassword) <= self::MAX_PASSWORD_LENGTH && hash_equals($hashedPassword, $plainPassword);
    }

    public function needsRehash(string $hashedPassword): bool
    {
        return false;
    }
}