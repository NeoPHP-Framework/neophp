<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel;

use NeoPHP\Component\Config\Contract\ConfigInterface;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Flash\Contract\FlashInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Kernel\Contract\KernelInterface;
use NeoPHP\Component\Session\Contract\SessionInterface;
use NeoPHP\Package\Security\Contract\SecurityInterface;
use NeoPHP\Package\Translation\Contract\TranslatorInterface;

class AppVariable
{
    public function __construct(protected ContainerInterface $container)
    {
    }

    public function getName(): string
    {
        return $this->container->has(ConfigInterface::class) ? (string) $this->container->get(ConfigInterface::class)->get('framework.app.name', '') : '';
    }

    public function getEnvironment(): string
    {
        return $this->container->get(KernelInterface::class)->getEnvironment();
    }

    public function getDebug(): bool
    {
        return $this->container->get(KernelInterface::class)->isDebug();
    }

    public function getRequest(): ?Request
    {
        return $this->container->has(Request::class) ? $this->container->get(Request::class) : null;
    }

    public function getSession(): ?SessionInterface
    {
        return $this->container->has(SessionInterface::class) ? $this->container->get(SessionInterface::class) : null;
    }

    public function getUser(): ?object
    {
        return $this->container->has(SecurityInterface::class) ? $this->container->get(SecurityInterface::class)->getUser() : null;
    }

    public function getFlashes(?string $type = null): array
    {
        if (!$this->container->has(FlashInterface::class)) {
            return [];
        }

        $flash = $this->container->get(FlashInterface::class);

        return $type === null ? $flash->all() : $flash->get($type);
    }

    public function getLocale(): string
    {
        return $this->container->has(TranslatorInterface::class) ? $this->container->get(TranslatorInterface::class)->getLocale() : 'en';
    }

    public function getCurrentRoute(): ?string
    {
        $route = $this->getRequest()?->attributes->get('_route');

        return is_string($route) && $route !== '' ? $route : null;
    }

    public function getCurrentRouteParameters(): array
    {
        $parameters = $this->getRequest()?->attributes->all() ?? [];

        return array_filter($parameters, static fn (string|int $key): bool => !str_starts_with((string) $key, '_'), ARRAY_FILTER_USE_KEY);
    }

    public function __isset(string $name): bool
    {
        return method_exists($this, $this->getter($name));
    }

    public function __get(string $name): mixed
    {
        $getter = $this->getter($name);

        return method_exists($this, $getter) ? $this->{$getter}() : null;
    }

    protected function getter(string $name): string
    {
        return 'get' . str_replace('_', '', ucwords($name, '_'));
    }
}