<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Firewall;

use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Cookie\Contract\CookieInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\RedirectResponse;
use NeoPHP\Component\Routing\Contract\RoutingInterface;
use NeoPHP\Component\Routing\Route\Route;
use NeoPHP\Component\Session\Contract\SessionInterface;

class HttpUtils
{
    public function __construct(protected ContainerInterface $container)
    {
    }

    public function generateUrl(string $path, array $parameters = []): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1) {
            return $path;
        }

        if ($path === '' || str_starts_with($path, '/')) {
            return $this->getBasePath() . ($path === '' ? '/' : $path);
        }

        return $this->container->get(RoutingInterface::class)->generate($path, $parameters);
    }

    public function getBasePath(): string
    {
        $request = $this->container->has(Request::class) ? $this->container->get(Request::class) : null;

        return $request instanceof Request ? $request->getBasePath() : '';
    }

    public function checkRequestPath(Request $request, string $path): bool
    {
        $url = $this->generateUrl($path);
        $target = (string) (parse_url($url, PHP_URL_PATH) ?? '/');

        return Route::normalizePath(rawurldecode($request->getBasePath() . $request->getPath())) === Route::normalizePath($target);
    }

    public function createRedirectResponse(string $path, int $status = 302): RedirectResponse
    {
        return new RedirectResponse($this->generateUrl($path), $status);
    }

    public function isSafeTargetPath(mixed $path): bool
    {
        if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return false;
        }

        if (preg_match('/[\x00-\x20\x7F]/', $path) === 1) {
            return false;
        }

        $parts = parse_url($path);

        return is_array($parts) && !isset($parts['scheme']) && !isset($parts['host']);
    }

    public function getRequestTarget(Request $request): string
    {
        $query = $request->getQueryString();

        return $request->getBasePath() . $request->getPath() . ($query !== null && $query !== '' ? '?' . $query : '');
    }

    public function getSession(): SessionInterface
    {
        return $this->container->get(SessionInterface::class);
    }

    public function getCookies(): CookieInterface
    {
        return $this->container->get(CookieInterface::class);
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }
}