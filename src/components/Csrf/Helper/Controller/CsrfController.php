<?php

declare(strict_types=1);

namespace NeoPHP\Component\Csrf\Helper\Controller;

use NeoPHP\Component\Csrf\CsrfManagerInterface;

trait CsrfController
{
    abstract protected function get(string $id): mixed;

    protected function getCsrfToken(string $id): string
    {
        return $this->get(CsrfManagerInterface::class)->getToken($id);
    }

    protected function isCsrfTokenValid(string $id, ?string $token): bool
    {
        return $this->get(CsrfManagerInterface::class)->isTokenValid($id, $token);
    }
}