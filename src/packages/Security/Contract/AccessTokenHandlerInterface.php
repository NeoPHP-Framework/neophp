<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Contract;

interface AccessTokenHandlerInterface
{
    public function getUserFrom(string $accessToken): string|UserInterface;
}