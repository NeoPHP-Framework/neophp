<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Helper\Controller;

use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Security\Contract\UserInterface;
use NeoPHP\Package\Security\SecurityManagerInterface;

trait SecurityController
{
    abstract protected function get(string $id): mixed;

    protected function getUser(): ?UserInterface
    {
        return $this->get(SecurityManagerInterface::class)->getUser();
    }

    protected function isGranted(string|array $attribute, mixed $subject = null): bool
    {
        return $this->get(SecurityManagerInterface::class)->isGranted($attribute, $subject);
    }

    protected function denyAccessUnlessGranted(string|array $attribute, mixed $subject = null, string $message = 'Access Denied.'): void
    {
        $this->get(SecurityManagerInterface::class)->denyAccessUnlessGranted($attribute, $subject, $message);
    }

    protected function loginUser(UserInterface $user, ?string $firewall = null, bool $rememberMe = false): void
    {
        $this->get(SecurityManagerInterface::class)->login($user, $firewall, $rememberMe);
    }

    protected function logoutUser(): Response
    {
        return $this->get(SecurityManagerInterface::class)->logout();
    }

    protected function getLastAuthenticationError(bool $clear = true): ?string
    {
        return $this->get(SecurityManagerInterface::class)->getLastAuthenticationError($clear);
    }

    protected function getLastUsername(): string
    {
        return $this->get(SecurityManagerInterface::class)->getLastUsername();
    }
}