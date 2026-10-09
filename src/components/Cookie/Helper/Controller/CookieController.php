<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cookie\Helper\Controller;

use NeoPHP\Component\Cookie\CookieManagerInterface;

trait CookieController
{
    abstract protected function get(string $id): mixed;

    protected function getCookies(): CookieManagerInterface
    {
        return $this->get(CookieManagerInterface::class);
    }
}