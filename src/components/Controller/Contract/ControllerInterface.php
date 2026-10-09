<?php

declare(strict_types=1);

namespace NeoPHP\Component\Controller\Contract;

use NeoPHP\Component\Container\ContainerManagerInterface;

interface ControllerInterface
{
    public function setContainer(ContainerManagerInterface $container): void;
}