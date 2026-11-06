<?php

namespace NeoPHP\Package\Security\RememberMe;

interface RotatableTokenProviderInterface
{
    public function rotateToken(string $series, string $currentHash, string $newHash, int $lastUsed): bool;
}