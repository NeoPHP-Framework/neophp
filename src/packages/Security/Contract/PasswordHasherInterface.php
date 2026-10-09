<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Contract;

interface PasswordHasherInterface
{
    public const MAX_PASSWORD_LENGTH = 4096;

    public function hash(string $plainPassword): string;

    public function verify(string $hashedPassword, string $plainPassword): bool;

    public function needsRehash(string $hashedPassword): bool;
}