<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\RememberMe;

interface TokenProviderInterface
{
    public function createToken(PersistentToken $token): void;

    public function loadToken(string $series): ?PersistentToken;

    public function touchToken(string $series, int $lastUsed): void;

    public function deleteToken(string $series): void;

    public function findUserTokens(string $class, string $identifier): array;

    public function deleteUserTokens(string $class, string $identifier): int;
}