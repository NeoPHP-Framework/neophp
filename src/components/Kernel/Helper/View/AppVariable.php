<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Helper\View;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Flash\FlashManagerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Kernel\KernelManagerInterface;
use NeoPHP\Component\Session\SessionManagerInterface;
use NeoPHP\Package\Security\SecurityManagerInterface;
use NeoPHP\Package\Translation\TranslationManagerInterface;

/**
 * @internal
 */
class AppVariable
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    public function getName(): string
    {
        return $this->container->has(ConfigManagerInterface::class) ? (string) $this->container->get(ConfigManagerInterface::class)->get('framework.app.name', '') : '';
    }

    public function getEnvironment(): string
    {
        return $this->container->get(KernelManagerInterface::class)->getEnvironment();
    }

    public function getDebug(): bool
    {
        return $this->container->get(KernelManagerInterface::class)->isDebug();
    }

    public function getRequest(): ?Request
    {
        return $this->container->has(Request::class) ? $this->container->get(Request::class) : null;
    }

    public function getSession(): ?SessionManagerInterface
    {
        return $this->container->has(SessionManagerInterface::class) ? $this->container->get(SessionManagerInterface::class) : null;
    }

    public function getUser(): ?object
    {
        return $this->container->has(SecurityManagerInterface::class) ? $this->container->get(SecurityManagerInterface::class)->getUser() : null;
    }

    public function getFlashes(?string $type = null): array
    {
        if (!$this->container->has(FlashManagerInterface::class)) {
            return [];
        }

        $flash = $this->container->get(FlashManagerInterface::class);

        return $type === null ? $flash->all() : $flash->get($type);
    }

    public function getLocale(): string
    {
        return $this->container->has(TranslationManagerInterface::class) ? $this->container->get(TranslationManagerInterface::class)->getLocale() : 'en';
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