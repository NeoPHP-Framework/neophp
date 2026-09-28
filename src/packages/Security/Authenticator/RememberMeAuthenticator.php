<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Authenticator;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Security\Authentication\Passport;
use NeoPHP\Package\Security\Contract\AbstractAuthenticator;
use NeoPHP\Package\Security\Contract\TokenInterface;
use NeoPHP\Package\Security\Contract\UserInterface;
use NeoPHP\Package\Security\Exception\AuthenticationException;
use NeoPHP\Package\Security\Exception\SecurityException;
use NeoPHP\Package\Security\RememberMe\RememberMeHandler;
use NeoPHP\Package\Security\Token\RememberMeToken;
use NeoPHP\Package\Security\Token\TokenStorage;

class RememberMeAuthenticator extends AbstractAuthenticator
{
    public function __construct(protected RememberMeHandler $handler, protected TokenStorage $tokens)
    {
    }

    public function supports(Request $request): bool
    {
        return $this->handler->hasCookie($request) && $this->tokens->getToken() === null;
    }

    public function authenticate(Request $request): Passport
    {
        $cookie = $this->handler->parse($request);

        if ($cookie === null) {
            throw new AuthenticationException('The remember-me cookie is malformed.');
        }

        if ($cookie['expires'] <= time()) {
            throw new AuthenticationException('The remember-me cookie has expired.');
        }

        return Passport::selfValidating($cookie['identifier'])->addCheck(function (UserInterface $user) use ($cookie): void {
            if (!$this->handler->validate($user, $cookie)) {
                throw new AuthenticationException('The remember-me cookie is invalid.');
            }
        });
    }

    public function createToken(Passport $passport, string $firewall): TokenInterface
    {
        $user = $passport->getUser() ?? throw new SecurityException('The passport has no user.');

        return new RememberMeToken($user, $firewall, static::class, $passport->getAttributes());
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewall): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $this->handler->clearCookie();

        return null;
    }
}