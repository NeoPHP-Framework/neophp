<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Contract;

use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Csrf\Contract\CsrfInterface;
use NeoPHP\Component\Event\Contract\EventDispatcherInterface;
use NeoPHP\Component\Http\Exception\AccessDeniedHttpException;
use NeoPHP\Component\Http\Exception\HttpException;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Security\Authentication\AuthenticationManager;
use NeoPHP\Package\Security\Authenticator\RememberMeAuthenticator;
use NeoPHP\Package\Security\Authorization\AccessDecisionManager;
use NeoPHP\Package\Security\Authorization\AccessMap;
use NeoPHP\Package\Security\Event\LoginFailureEvent;
use NeoPHP\Package\Security\Event\LoginSuccessEvent;
use NeoPHP\Package\Security\Event\LogoutEvent;
use NeoPHP\Package\Security\Exception\AccessDeniedException;
use NeoPHP\Package\Security\Exception\AuthenticationException;
use NeoPHP\Package\Security\Exception\UserNotFoundException;
use NeoPHP\Package\Security\Firewall\Firewall;
use NeoPHP\Package\Security\Firewall\FirewallFactory;
use NeoPHP\Package\Security\Firewall\FirewallMap;
use NeoPHP\Package\Security\Firewall\HttpUtils;
use NeoPHP\Package\Security\RememberMe\PersistentToken;
use NeoPHP\Package\Security\Token\NullToken;
use NeoPHP\Package\Security\Token\RememberMeToken;
use NeoPHP\Package\Security\Token\SecurityToken;
use NeoPHP\Package\Security\Token\TokenStorage;
use NeoPHP\Package\Security\User\UserClass;
use NeoPHP\Package\Security\Voter\AuthenticatedVoter;
use NeoPHP\Package\Translation\Contract\TranslatorInterface;
use Throwable;

abstract class AbstractSecurity implements SecurityInterface
{
    public const TRANSLATION_DOMAIN = 'security';

    protected ContainerInterface $container;

    protected TokenStorage $tokens;

    protected FirewallMap $map;

    protected FirewallFactory $factory;

    protected AuthenticationManager $authentication;

    protected AccessDecisionManager $decisions;

    protected AccessMap $accessMap;

    protected HttpUtils $http;

    protected bool $enabled = false;

    protected ?string $firewall = null;

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getToken(): ?TokenInterface
    {
        return $this->tokens->getToken();
    }

    public function setToken(?TokenInterface $token): void
    {
        $this->tokens->setToken($token);
    }

    public function getUser(): ?UserInterface
    {
        return $this->getToken()?->getUser();
    }

    public function isGranted(string|array $attribute, mixed $subject = null): bool
    {
        return $this->decisions->decide($this->getToken() ?? new NullToken($this->firewall), (array) $attribute, $subject);
    }

    public function denyAccessUnlessGranted(string|array $attribute, mixed $subject = null, string $message = 'Access Denied.'): void
    {
        if (!$this->isGranted($attribute, $subject)) {
            throw new AccessDeniedException($message, (array) $attribute, $subject);
        }
    }

    public function getFirewall(): ?Firewall
    {
        return $this->firewall !== null ? $this->factory->get($this->firewall) : null;
    }

    public function getFirewallMap(): FirewallMap
    {
        return $this->map;
    }

    public function getAccessDecisionManager(): AccessDecisionManager
    {
        return $this->decisions;
    }

    public function getAccessMap(): AccessMap
    {
        return $this->accessMap;
    }

    public function handleRequest(Request $request): ?Response
    {
        $this->tokens->reset();
        $this->firewall = $this->enabled ? $this->map->match($request) : null;

        if ($this->firewall === null) {
            $this->checkAccess($request);

            return null;
        }

        $request->attributes->set('_firewall', $this->firewall);
        $firewall = $this->factory->get($this->firewall);

        if (!$firewall->isSecurityEnabled()) {
            return null;
        }

        if (!$firewall->isStateless()) {
            $this->tokens->setInitializer(fn (): ?TokenInterface => $this->loadToken($firewall));
        }

        $logout = $firewall->getLogout();

        if ($logout !== null && $this->http->checkRequestPath($request, (string) $logout['path'])) {
            return $this->handleLogout($request, $firewall, $logout);
        }

        foreach ($firewall->getAuthenticators() as $name => $authenticator) {
            if (!$authenticator->supports($request)) {
                continue;
            }

            $response = $this->authenticate($request, $firewall, (string) $name, $authenticator);

            if ($response !== null) {
                return $response;
            }

            break;
        }

        $this->checkAccess($request);

        return null;
    }

    public function handleException(Request $request, Throwable $exception): ?Response
    {
        if (!$exception instanceof AccessDeniedException && !$exception instanceof AccessDeniedHttpException && !$exception instanceof AuthenticationException) {
            return null;
        }

        $firewall = $this->getFirewall();

        if ($firewall === null || !$firewall->isSecurityEnabled() || $firewall->getEntryPoint() === null) {
            return null;
        }

        $token = $this->getToken();

        if (!$exception instanceof AuthenticationException && $token !== null && $token->isAuthenticated()) {
            if (!$token->isRemembered() || !$exception instanceof AccessDeniedException || !in_array(AuthenticatedVoter::IS_AUTHENTICATED_FULLY, $exception->getAttributes(), true)) {
                return null;
            }
        }

        $authentication = $exception instanceof AuthenticationException
            ? $exception
            : new AuthenticationException('Full authentication is required to access this resource.', 0, $exception);

        return $firewall->getEntryPoint()->start($request, $this->translateException($authentication));
    }

    public function login(UserInterface $user, ?string $firewall = null, bool $rememberMe = false): void
    {
        $name = $firewall ?? $this->firewall ?? throw new AuthenticationException('Unable to log the user in: no firewall matches the current request, pass the firewall name.');
        $instance = $this->factory->get($name);
        $token = new SecurityToken($user, $name, 'programmatic');

        $this->firewall ??= $name;
        $this->storeToken($instance, $token);

        if ($rememberMe) {
            $instance->getRememberMe()?->createCookie($user);
        }

        $this->dispatch(new LoginSuccessEvent($token, $name, $this->currentRequest(), null, 'programmatic'));
    }

    public function logout(): Response
    {
        $firewall = $this->getFirewall();
        $request = $this->currentRequest() ?? new Request();

        if ($firewall === null) {
            $this->tokens->setToken(null);

            return $this->http->createRedirectResponse('/');
        }

        return $this->doLogout($request, $firewall, $firewall->getLogout() ?? FirewallFactory::LOGOUT_OPTIONS);
    }

    public function getRememberMeTokens(?UserInterface $user = null, ?string $firewall = null): array
    {
        [$handler, $user] = $this->rememberMeContext($user, $firewall);

        if ($handler === null || $user === null) {
            return [];
        }

        $request = $this->currentRequest();
        $current = $request === null ? null : $handler->getSeries($request);

        return array_map(
            static fn (PersistentToken $token): array => $token->toArray() + ['current' => $token->series === $current],
            $handler->getProvider()->findUserTokens(UserClass::of($user), $user->getUserIdentifier()),
        );
    }

    public function revokeRememberMeToken(string $series, ?UserInterface $user = null, ?string $firewall = null): bool
    {
        [$handler, $user] = $this->rememberMeContext($user, $firewall);
        $token = $handler?->getProvider()->loadToken($series);

        if ($token === null || $user === null || $token->class !== UserClass::of($user) || $token->identifier !== $user->getUserIdentifier()) {
            return false;
        }

        $handler->getProvider()->deleteToken($series);

        return true;
    }

    public function revokeAllRememberMeTokens(?UserInterface $user = null, ?string $firewall = null): int
    {
        [$handler, $user] = $this->rememberMeContext($user, $firewall);

        return $handler === null || $user === null ? 0 : $handler->getProvider()->deleteUserTokens(UserClass::of($user), $user->getUserIdentifier());
    }

    public function getLogoutPath(?string $firewall = null): ?string
    {
        $name = $firewall ?? $this->firewall;

        if ($name === null || !$this->map->has($name)) {
            return null;
        }

        $logout = $this->factory->get($name)->getLogout();

        if ($logout === null) {
            return null;
        }

        $path = $this->http->generateUrl((string) $logout['path']);

        if ($logout['enable_csrf'] && $this->container->has(CsrfInterface::class)) {
            $token = $this->container->get(CsrfInterface::class)->getToken((string) $logout['csrf_token_id']);
            $path .= (str_contains($path, '?') ? '&' : '?') . rawurlencode((string) $logout['csrf_parameter']) . '=' . rawurlencode($token);
        }

        return $path;
    }

    public function getLastAuthenticationError(bool $clear = true): ?string
    {
        $session = $this->http->getSession();
        $error = $clear ? $session->remove(self::LAST_ERROR) : $session->get(self::LAST_ERROR);

        return is_string($error) ? $error : null;
    }

    public function getLastUsername(): string
    {
        return (string) $this->http->getSession()->get(self::LAST_USERNAME, '');
    }

    protected function authenticate(Request $request, Firewall $firewall, string $name, AuthenticatorInterface $authenticator): ?Response
    {
        try {
            [$token, $passport] = $this->authentication->authenticate($request, $firewall, $authenticator);
        } catch (AuthenticationException $exception) {
            $this->translateException($exception);
            $event = $this->dispatch(new LoginFailureEvent($exception, $firewall->getName(), $request, $authenticator->onAuthenticationFailure($request, $exception), $name));

            return $event instanceof LoginFailureEvent ? $event->getResponse() : null;
        }

        $this->storeToken($firewall, $token);

        $rememberMe = $firewall->getRememberMe();

        if ($rememberMe !== null && !$authenticator instanceof RememberMeAuthenticator && $rememberMe->isRequested($passport)) {
            $rememberMe->createCookie($token->getUser() ?? throw new UserNotFoundException('The token has no user.'));
        }

        $event = $this->dispatch(new LoginSuccessEvent($token, $firewall->getName(), $request, $authenticator->onAuthenticationSuccess($request, $token, $firewall->getName()), $name));

        return $event instanceof LoginSuccessEvent ? $event->getResponse() : null;
    }

    protected function checkAccess(Request $request): void
    {
        $roles = $this->accessMap->match($request);

        $trace = $this->decisions->getTrace();

        if ($roles === null || $roles === [] || $roles === [AuthenticatedVoter::PUBLIC_ACCESS]) {
            $trace?->setAccessControl($roles, null);

            return;
        }

        $granted = $this->isGranted($roles);
        $trace?->setAccessControl($roles, $granted);

        if (!$granted) {
            throw new AccessDeniedException('Access Denied.', $roles);
        }
    }

    protected function storeToken(Firewall $firewall, TokenInterface $token): void
    {
        $this->tokens->setToken($token);

        if ($firewall->isStateless()) {
            return;
        }

        $session = $this->http->getSession();
        $session->start();
        $session->regenerate(true);

        $user = $token->getUser();

        $session->set($firewall->getSessionKey(), [
            'identifier' => $token->getUserIdentifier(),
            'class' => $user !== null ? UserClass::of($user) : null,
            'authenticator' => $token->getAuthenticator(),
            'remembered' => $token->isRemembered(),
            'fingerprint' => $user !== null ? self::fingerprint($user) : null,
            'attributes' => $token->getAttributes(),
        ]);
    }

    protected function loadToken(Firewall $firewall): ?TokenInterface
    {
        $session = $this->http->getSession();
        $data = $session->get($firewall->getSessionKey());

        if (!is_array($data) || !is_string($data['identifier'] ?? null)) {
            return null;
        }

        try {
            $user = $firewall->getProvider()->loadUserByIdentifier($data['identifier']);
        } catch (UserNotFoundException) {
            $session->remove($firewall->getSessionKey());

            return null;
        }

        if (($data['fingerprint'] ?? null) !== self::fingerprint($user)) {
            $session->remove($firewall->getSessionKey());

            return null;
        }

        $class = !empty($data['remembered']) ? RememberMeToken::class : SecurityToken::class;

        return new $class($user, $firewall->getName(), is_string($data['authenticator'] ?? null) ? $data['authenticator'] : null, (array) ($data['attributes'] ?? []));
    }

    protected function handleLogout(Request $request, Firewall $firewall, array $logout): Response
    {
        $methods = array_map('strtoupper', array_map('strval', (array) $logout['methods']));

        if ($methods !== [] && !in_array($request->getRealMethod(), $methods, true)) {
            throw new HttpException(405, 'The logout only accepts the {methods} method(s).', ['Allow' => implode(', ', $methods)], ['methods' => implode(', ', $methods)]);
        }

        if ($logout['enable_csrf']) {
            $token = $request->request->get((string) $logout['csrf_parameter']) ?? $request->query->get((string) $logout['csrf_parameter']);

            if (!$this->container->has(CsrfInterface::class) || !$this->container->get(CsrfInterface::class)->isTokenValid((string) $logout['csrf_token_id'], is_string($token) ? $token : null)) {
                throw new AccessDeniedException('Invalid logout CSRF token.');
            }
        }

        return $this->doLogout($request, $firewall, $logout);
    }

    protected function doLogout(Request $request, Firewall $firewall, array $logout): Response
    {
        $token = $this->getToken();
        $event = $this->dispatch(new LogoutEvent($request, $token, $this->http->createRedirectResponse((string) $logout['target']), $firewall->getName()));
        $response = $event instanceof LogoutEvent ? $event->getResponse() : $this->http->createRedirectResponse((string) $logout['target']);

        $firewall->getRememberMe()?->clearCookie();

        foreach ((array) $logout['clear_cookies'] as $cookie) {
            $this->http->getCookies()->remove((string) $cookie);
        }

        $this->tokens->setToken(null);

        if (!$firewall->isStateless()) {
            $session = $this->http->getSession();

            if ($logout['invalidate_session']) {
                $session->invalidate();
            } else {
                $session->remove($firewall->getSessionKey());
            }
        }

        return $response;
    }

    protected function translateException(AuthenticationException $exception): AuthenticationException
    {
        if (!$this->container->has(TranslatorInterface::class)) {
            return $exception;
        }

        try {
            $exception->setTranslatedSafeMessage($this->container->get(TranslatorInterface::class)->translate($exception->getSafeMessageKey(), $exception->getSafeMessageParameters(), self::TRANSLATION_DOMAIN));
        } catch (Throwable) {
            $exception->setTranslatedSafeMessage(null);
        }

        return $exception;
    }

    protected function dispatch(object $event): object
    {
        $this->decisions->getTrace()?->addEvent($event);

        if ($this->container->has(EventDispatcherInterface::class)) {
            return $this->container->get(EventDispatcherInterface::class)->dispatch($event);
        }

        return $event;
    }

    protected function rememberMeContext(?UserInterface $user, ?string $firewall): array
    {
        $name = $firewall ?? $this->firewall;
        $handler = $name === null ? null : $this->factory->get($name)->getRememberMe();

        return [$handler?->getProvider() === null ? null : $handler, $user ?? $this->getUser()];
    }

    protected function currentRequest(): ?Request
    {
        return $this->container->has(Request::class) ? $this->container->get(Request::class) : null;
    }

    protected static function fingerprint(UserInterface $user): string
    {
        return hash('sha256', UserClass::of($user) . '|' . ($user instanceof PasswordAuthenticatedUserInterface ? (string) $user->getPassword() : ''));
    }
}