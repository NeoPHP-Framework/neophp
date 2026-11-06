<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Jwt;

use NeoPHP\Package\Security\Contract\AccessTokenHandlerInterface;
use NeoPHP\Package\Security\Contract\UserInterface;
use NeoPHP\Package\Security\Exception\BadCredentialsException;

class JwtTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(protected JwtManager $jwt)
    {
    }

    public function getUserFrom(string $accessToken): string|UserInterface
    {
        $subject = $this->jwt->decode($accessToken)['sub'] ?? null;

        if (!is_string($subject) || $subject === '') {
            throw new BadCredentialsException('Invalid access token.');
        }

        return $subject;
    }
}