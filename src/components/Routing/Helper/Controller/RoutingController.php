<?php

declare(strict_types=1);

namespace NeoPHP\Component\Routing\Helper\Controller;

use NeoPHP\Component\Http\HttpManagerInterface;
use NeoPHP\Component\Http\Response\RedirectResponse;
use NeoPHP\Component\Routing\RoutingManagerInterface;

trait RoutingController
{
    abstract protected function get(string $id): mixed;

    protected function generateUrl(string $route, array $parameters = [], bool $absolute = false): string
    {
        return $this->get(RoutingManagerInterface::class)->generate($route, $parameters, $absolute);
    }

    protected function redirectToRoute(string $route, array $parameters = [], int $status = 302): RedirectResponse
    {
        return $this->get(HttpManagerInterface::class)->redirect($this->generateUrl($route, $parameters), $status);
    }
}