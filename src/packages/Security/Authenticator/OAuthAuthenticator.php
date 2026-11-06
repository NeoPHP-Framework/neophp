<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Authenticator;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Security\Authentication\Passport;
use NeoPHP\Package\Security\Contract\AbstractAuthenticator;
use NeoPHP\Package\Security\Contract\OAuthUserResolverInterface;
use NeoPHP\Package\Security\Contract\TokenInterface;
use NeoPHP\Package\Security\Exception\AuthenticationException;
use NeoPHP\Package\Security\Exception\SecurityException;
use NeoPHP\Package\Security\Firewall\HttpUtils;
use NeoPHP\Package\Security\OAuth\OAuthManager;
use NeoPHP\Package\Security\SecurityManagerInterface;

class OAuthAuthenticator extends AbstractAuthenticator
{
    public function __construct(protected OAuthManager $oauth, protected HttpUtils $http, protected ContainerManagerInterface $container)
    {
    }

    public function supports(Request $request): bool
    {
        return $this->oauth->matchCallback($request) !== null;
    }

    public function authenticate(Request $request): Passport
    {
        $provider = (string) $this->oauth->matchCallback($request);
        $user = $this->resolver()->resolve($this->oauth->fetchUser($provider, $request));

        return Passport::selfValidating($user->getUserIdentifier())->setUser($user);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewall): ?Response
    {
        return $this->http->createRedirectResponse((string) ($this->oauth->getConfig()['default_target_path'] ?? '/'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $this->http->getSession()->set(SecurityManagerInterface::LAST_ERROR, $exception->getSafeMessage());

        return $this->http->createRedirectResponse((string) ($this->oauth->getConfig()['failure_path'] ?? '/login'));
    }

    protected function resolver(): OAuthUserResolverInterface
    {
        $class = (string) ($this->oauth->getConfig()['resolver'] ?? '');
        $resolver = $class !== '' && class_exists($class) ? $this->container->get($class) : null;

        if (!$resolver instanceof OAuthUserResolverInterface) {
            throw new SecurityException('The "oauth.resolver" option must be a class implementing {interface}.', 0, null, ['interface' => OAuthUserResolverInterface::class]);
        }

        return $resolver;
    }
}