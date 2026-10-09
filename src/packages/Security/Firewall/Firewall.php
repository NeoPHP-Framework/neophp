<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Firewall;

use NeoPHP\Package\Security\Contract\AuthenticatorInterface;
use NeoPHP\Package\Security\Contract\EntryPointInterface;
use NeoPHP\Package\Security\Contract\UserCheckerInterface;
use NeoPHP\Package\Security\Contract\UserProviderInterface;
use NeoPHP\Package\Security\Exception\SecurityException;
use NeoPHP\Package\Security\RememberMe\RememberMeHandler;
use NeoPHP\Package\Security\Throttle\LoginThrottler;

class Firewall
{
    public const SESSION_PREFIX = '_security_';

    public function __construct(
        protected string $name,
        protected array $config = [],
        protected ?UserProviderInterface $provider = null,
        protected array $authenticators = [],
        protected ?EntryPointInterface $entryPoint = null,
        protected ?UserCheckerInterface $userChecker = null,
        protected ?LoginThrottler $throttler = null,
        protected ?RememberMeHandler $rememberMe = null,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function isSecurityEnabled(): bool
    {
        return (bool) ($this->config['security'] ?? true);
    }

    public function isStateless(): bool
    {
        return (bool) ($this->config['stateless'] ?? false);
    }

    public function getContext(): string
    {
        return (string) ($this->config['context'] ?? $this->name);
    }

    public function getSessionKey(): string
    {
        return self::SESSION_PREFIX . $this->getContext();
    }

    public function hasProvider(): bool
    {
        return $this->provider !== null;
    }

    public function getProvider(): UserProviderInterface
    {
        if ($this->provider === null) {
            throw new SecurityException('The firewall "{firewall}" has no user provider: define one under "providers" and set the "provider" option of the firewall.', 0, null, ['firewall' => $this->name]);
        }

        return $this->provider;
    }

    public function getAuthenticators(): array
    {
        return $this->authenticators;
    }

    public function getAuthenticator(string $name): ?AuthenticatorInterface
    {
        return $this->authenticators[$name] ?? null;
    }

    public function getEntryPoint(): ?EntryPointInterface
    {
        return $this->entryPoint;
    }

    public function getUserChecker(): ?UserCheckerInterface
    {
        return $this->userChecker;
    }

    public function getThrottler(): ?LoginThrottler
    {
        return $this->throttler;
    }

    public function getRememberMe(): ?RememberMeHandler
    {
        return $this->rememberMe;
    }

    public function getLogout(): ?array
    {
        return isset($this->config['logout']) && is_array($this->config['logout']) ? $this->config['logout'] : null;
    }
}