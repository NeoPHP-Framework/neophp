<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Helper\View;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\View\Contract\ViewGlobalInterface;

/**
 * @internal
 */
class AppViewHelper implements ViewGlobalInterface
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    public function getName(): string
    {
        return 'app';
    }

    public function getValue(): mixed
    {
        return new AppVariable($this->container);
    }
}