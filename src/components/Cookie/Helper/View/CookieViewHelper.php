<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cookie\Helper\View;

use NeoPHP\Component\Cookie\CookieManagerInterface;
use NeoPHP\Component\View\Contract\ViewFunctionInterface;

/**
 * @internal
 */
class CookieViewHelper implements ViewFunctionInterface
{
    public function __construct(protected CookieManagerInterface $cookies)
    {
    }

    public function getName(): string
    {
        return 'cookie';
    }

    public function __invoke(string $name, mixed $default = null, bool $signed = false): mixed
    {
        return $signed ? $this->cookies->getSigned($name, $default) : $this->cookies->get($name, $default);
    }
}