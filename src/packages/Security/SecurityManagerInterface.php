<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security;

use NeoPHP\Component\Http\Exception\HttpException;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Session\Exception\SessionException;
use NeoPHP\Package\Security\Contract\TokenInterface;
use NeoPHP\Package\Security\Contract\UserInterface;
use NeoPHP\Package\Security\Exception\AccessDeniedException;
use NeoPHP\Package\Security\Exception\AuthenticationException;
use NeoPHP\Package\Security\Firewall\Firewall;
use Throwable;

interface SecurityManagerInterface
{
    public const LAST_USERNAME = '_security.last_username';

    public const LAST_ERROR = '_security.last_error';

    /**
     * Tells whether security.yaml defines at least one firewall or one access_control rule.
     *
     * @return bool True when the security is enabled
     */
    public function isEnabled(): bool;

    /**
     * Returns the authentication of the current request, loaded from the session on first access.
     *
     * @return TokenInterface|null The token, or null when the user is anonymous
     */
    public function getToken(): ?TokenInterface;

    /**
     * Replaces the authentication of the current request, without storing it in the session.
     *
     * @param TokenInterface|null $token The token, or null for an anonymous user
     * @return void
     */
    public function setToken(?TokenInterface $token): void;

    /**
     * Returns the logged in user.
     *
     * @return UserInterface|null The user, or null when the user is anonymous
     */
    public function getUser(): ?UserInterface;

    /**
     * Tells whether the current user is granted an attribute, with the roles, the role hierarchy and the voters.
     *
     * @param string|array<string> $attribute A role, IS_AUTHENTICATED*, PUBLIC_ACCESS or a voter attribute; an array is granted when one of them is granted
     * @param mixed $subject The subject voted on (an entity...)
     * @return bool True when the access is granted
     */
    public function isGranted(string|array $attribute, mixed $subject = null): bool;

    /**
     * Throws when the current user is not granted an attribute.
     *
     * @param string|array<string> $attribute A role, IS_AUTHENTICATED*, PUBLIC_ACCESS or a voter attribute
     * @param mixed $subject The subject voted on
     * @param string $message Message of the exception
     * @return void
     * @throws AccessDeniedException When the access is denied (403, or the entry point for an anonymous user)
     */
    public function denyAccessUnlessGranted(string|array $attribute, mixed $subject = null, string $message = 'Access Denied.'): void;

    /**
     * Returns the firewall matching the current request.
     *
     * @return Firewall|null The firewall, or null when none matches
     */
    public function getFirewall(): ?Firewall;

    /**
     * Matches the firewall of a request, handles the logout and the authenticators, then checks the access_control rules; called by the kernel.
     *
     * @param Request $request The request
     * @return Response|null A response of an authenticator or of the logout, or null to continue the request
     * @throws AccessDeniedException When an access_control rule denies the access or the logout CSRF token is invalid
     * @throws HttpException When the logout is called with a method that is not allowed (405)
     */
    public function handleRequest(Request $request): ?Response;

    /**
     * Turns an access denied or an authentication exception into the response of the entry point of the firewall; called by the kernel.
     *
     * @param Request $request The request
     * @param Throwable $exception The exception
     * @return Response|null The response of the entry point (login redirection, 401...), or null to let the error page render the exception
     */
    public function handleException(Request $request, Throwable $exception): ?Response;

    /**
     * Logs a user in programmatically (after a registration...) and dispatches the LoginSuccessEvent.
     *
     * @param UserInterface $user The user
     * @param string|null $firewall Name of the firewall, the firewall of the current request when null
     * @param bool $rememberMe Sets the remember-me cookie
     * @return void
     * @throws AuthenticationException When no firewall is given and none matches the current request
     * @throws SessionException When the session cannot be started
     */
    public function login(UserInterface $user, ?string $firewall = null, bool $rememberMe = false): void;

    /**
     * Logs the current user out, dispatches the LogoutEvent and returns the redirection to the logout target.
     *
     * @return Response The redirection
     * @throws SessionException When the session cannot be invalidated
     */
    public function logout(): Response;

    /**
     * Returns the URL of the logout, with the CSRF token when it is enabled.
     *
     * @param string|null $firewall Name of the firewall, the firewall of the current request when null
     * @return string|null The URL, or null when the firewall has no logout
     */
    public function getLogoutPath(?string $firewall = null): ?string;

    /**
     * Returns the devices remembered for a user (remember_me with storage: database).
     *
     * @param UserInterface|null $user The user, the current user when null
     * @param string|null $firewall Name of the firewall, the firewall of the current request when null
     * @return list<array<string, mixed>> The devices: series, created_at, last_used_at, expires_at, user_agent, ip and current (this browser)
     */
    public function getRememberMeTokens(?UserInterface $user = null, ?string $firewall = null): array;

    /**
     * Revokes one remembered device of a user.
     *
     * @param string $series Series of the device
     * @param UserInterface|null $user The user, the current user when null
     * @param string|null $firewall Name of the firewall, the firewall of the current request when null
     * @return bool False when the device does not exist or does not belong to the user
     */
    public function revokeRememberMeToken(string $series, ?UserInterface $user = null, ?string $firewall = null): bool;

    /**
     * Revokes every remembered device of a user (after a password reset, "log out everywhere").
     *
     * @param UserInterface|null $user The user, the current user when null
     * @param string|null $firewall Name of the firewall, the firewall of the current request when null
     * @return int The number of revoked devices
     */
    public function revokeAllRememberMeTokens(?UserInterface $user = null, ?string $firewall = null): int;

    /**
     * Returns the safe message of the last login error, translated in the security domain.
     *
     * @param bool $clear Removes the error from the session
     * @return string|null The message, or null when the last login did not fail
     * @throws SessionException When the session cannot be started
     */
    public function getLastAuthenticationError(bool $clear = true): ?string;

    /**
     * Returns the username submitted on the last login attempt.
     *
     * @return string The username, empty when none was submitted
     * @throws SessionException When the session cannot be started
     */
    public function getLastUsername(): string;
}