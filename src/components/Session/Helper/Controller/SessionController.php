<?php

declare(strict_types=1);

namespace NeoPHP\Component\Session\Helper\Controller;

use NeoPHP\Component\Session\SessionManagerInterface;

trait SessionController
{
    abstract protected function get(string $id): mixed;

    protected function getSession(): SessionManagerInterface
    {
        return $this->get(SessionManagerInterface::class);
    }
}