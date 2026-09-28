<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Helper\View;

use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Kernel\AppVariable;
use NeoPHP\Component\View\Contract\ViewGlobalInterface;

class AppViewHelper implements ViewGlobalInterface
{
    public function __construct(protected ContainerInterface $container)
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