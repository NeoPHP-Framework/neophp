<?php

namespace NeoPHP\Package\Security\RememberMe;

interface RotatableTokenProviderInterface extends TokenProviderInterface
{
    public function rotateToken(string $series, string $currentHash, string $newHash, int $lastUsed): bool;
}