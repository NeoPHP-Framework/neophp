<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel;

use NeoPHP\Component\Config\ConfigManager;
use NeoPHP\Component\Container\ContainerManager;
use NeoPHP\Component\Controller\ControllerManager;
use NeoPHP\Component\Event\EventManager;
use NeoPHP\Component\Exception\ExceptionManager;
use NeoPHP\Component\Http\HttpManager;
use NeoPHP\Component\Kernel\Attribute\Component;
use NeoPHP\Component\Kernel\Contract\AbstractKernel;
use NeoPHP\Component\Kernel\Provider\KernelProvider;
use NeoPHP\Component\Middleware\MiddlewareManager;
use NeoPHP\Component\Routing\RoutingManager;
use NeoPHP\Package\Dotenv\DotenvManager;
use NeoPHP\Package\Yaml\YamlManager;

#[Component(provider: KernelProvider::class, requires: [
    ContainerManager::class,
    ExceptionManager::class,
    YamlManager::class,
    DotenvManager::class,
    ConfigManager::class,
    HttpManager::class,
    EventManager::class,
    MiddlewareManager::class,
    RoutingManager::class,
    ControllerManager::class,
])]
final class KernelManager extends AbstractKernel
{
}