<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Contract;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Security\Firewall\Firewall;
use Throwable;

interface SecurityInterface
{
    public const LAST_USERNAME = '_security.last_username';

    public const LAST_ERROR = '_security.last_error';

    public function isEnabled(): bool;

    public function getToken(): ?TokenInterface;

    public function setToken(?TokenInterface $token): void;

    public function getUser(): ?UserInterface;

    public function isGranted(string|array $attribute, mixed $subject = null): bool;

    public function denyAccessUnlessGranted(string|array $attribute, mixed $subject = null, string $message = 'Access Denied.'): void;

    public function getFirewall(): ?Firewall;

    public function handleRequest(Request $request): ?Response;

    public function handleException(Request $request, Throwable $exception): ?Response;

    public function login(UserInterface $user, ?string $firewall = null, bool $rememberMe = false): void;

    public function logout(): Response;

    public function getLogoutPath(?string $firewall = null): ?string;

    public function getRememberMeTokens(?UserInterface $user = null, ?string $firewall = null): array;

    public function revokeRememberMeToken(string $series, ?UserInterface $user = null, ?string $firewall = null): bool;

    public function revokeAllRememberMeTokens(?UserInterface $user = null, ?string $firewall = null): int;

    public function getLastAuthenticationError(bool $clear = true): ?string;

    public function getLastUsername(): string;
}