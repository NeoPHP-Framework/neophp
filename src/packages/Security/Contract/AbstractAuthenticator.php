<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Contract;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Security\Authentication\Passport;
use NeoPHP\Package\Security\Exception\AuthenticationException;
use NeoPHP\Package\Security\Exception\UserNotFoundException;
use NeoPHP\Package\Security\Token\SecurityToken;

abstract class AbstractAuthenticator implements AuthenticatorInterface
{
    public function createToken(Passport $passport, string $firewall): TokenInterface
    {
        $user = $passport->getUser();

        if ($user === null) {
            throw new UserNotFoundException('The passport has no user.');
        }

        return new SecurityToken($user, $firewall, static::class, $passport->getAttributes());
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewall): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => $exception->getSafeMessage()], 401);
    }
}