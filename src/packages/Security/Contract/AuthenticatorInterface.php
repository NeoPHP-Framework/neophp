<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Contract;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Security\Authentication\Passport;
use NeoPHP\Package\Security\Exception\AuthenticationException;

interface AuthenticatorInterface
{
    public function supports(Request $request): bool;

    public function authenticate(Request $request): Passport;

    public function createToken(Passport $passport, string $firewall): TokenInterface;

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewall): ?Response;

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response;
}