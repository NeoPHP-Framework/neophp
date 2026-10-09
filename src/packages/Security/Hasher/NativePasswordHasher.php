<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Hasher;

use NeoPHP\Package\Security\Contract\PasswordHasherInterface;
use NeoPHP\Package\Security\Exception\SecurityException;

class NativePasswordHasher implements PasswordHasherInterface
{
    protected string $algorithm;

    protected array $options = [];

    public function __construct(string $algorithm = 'auto', ?int $cost = null, ?int $memoryCost = null, ?int $timeCost = null)
    {
        $this->algorithm = match (strtolower($algorithm)) {
            'auto', 'native' => PASSWORD_DEFAULT,
            'bcrypt' => PASSWORD_BCRYPT,
            'argon2i' => defined('PASSWORD_ARGON2I') ? PASSWORD_ARGON2I : throw new SecurityException('The "argon2i" algorithm is not supported by this PHP build.'),
            'argon2id' => defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : throw new SecurityException('The "argon2id" algorithm is not supported by this PHP build.'),
            default => throw new SecurityException('Unknown password hashing algorithm "{algorithm}": use auto, bcrypt, argon2i, argon2id or plaintext.', 0, null, ['algorithm' => $algorithm]),
        };

        if ($this->algorithm === PASSWORD_BCRYPT && $cost !== null) {
            if ($cost < 4 || $cost > 31) {
                throw new SecurityException('The bcrypt cost must be between 4 and 31 ({cost} given).', 0, null, ['cost' => $cost]);
            }

            $this->options['cost'] = $cost;
        }

        if ($this->algorithm !== PASSWORD_BCRYPT) {
            if ($memoryCost !== null) {
                $this->options['memory_cost'] = $memoryCost;
            }

            if ($timeCost !== null) {
                $this->options['time_cost'] = $timeCost;
            }
        }
    }

    public function hash(string $plainPassword): string
    {
        if (strlen($plainPassword) > self::MAX_PASSWORD_LENGTH) {
            throw new SecurityException('The password is too long.');
        }

        return password_hash($plainPassword, $this->algorithm, $this->options);
    }

    public function verify(string $hashedPassword, string $plainPassword): bool
    {
        if ($hashedPassword === '' || strlen($plainPassword) > self::MAX_PASSWORD_LENGTH) {
            return false;
        }

        return password_verify($plainPassword, $hashedPassword);
    }

    public function needsRehash(string $hashedPassword): bool
    {
        return password_needs_rehash($hashedPassword, $this->algorithm, $this->options);
    }
}