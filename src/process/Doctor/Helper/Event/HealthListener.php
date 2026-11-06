<?php

declare(strict_types=1);

namespace NeoPHP\Process\Doctor\Helper\Event;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Kernel\Event\RequestEvent;
use NeoPHP\Component\Routing\Route\Route;
use NeoPHP\Component\Routing\RoutingManagerInterface;
use NeoPHP\Process\Doctor\Controller\HealthController;

/**
 * @internal
 */
class HealthListener
{
    public const CONFIG_KEY = 'framework.app.health';

    public const ROUTE = 'doctor_health';

    public const DEFAULT_PATH = '/health';

    protected bool $registered = false;

    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    #[AsListener(priority: 64)]
    public function onRequest(RequestEvent $event): void
    {
        if ($this->registered || !$this->container->has(RoutingManagerInterface::class) || !$this->container->has(ConfigManagerInterface::class)) {
            return;
        }

        $this->registered = true;
        $options = (array) $this->container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []);
        $routing = $this->container->get(RoutingManagerInterface::class);

        if (!filter_var($options['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN) || $routing->getRoutes()->has(self::ROUTE)) {
            return;
        }

        $routing->add(new Route(self::ROUTE, (string) ($options['path'] ?? self::DEFAULT_PATH), HealthController::class . '::check', ['GET']));
    }
}