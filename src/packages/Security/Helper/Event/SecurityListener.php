<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Helper\Event;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Kernel\Event\ExceptionEvent;
use NeoPHP\Component\Kernel\Event\RequestEvent;
use NeoPHP\Package\Security\Provider\SecurityProvider;
use NeoPHP\Package\Security\SecurityManagerInterface;

/**
 * @internal
 */
class SecurityListener
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    #[AsListener(priority: 8)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$this->container->has(SecurityManagerInterface::class) || !$this->container->get(SecurityProvider::CONFIG_ID)['enabled']) {
            return;
        }

        $response = $this->container->get(SecurityManagerInterface::class)->handleRequest($event->getRequest());

        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    #[AsListener(priority: 8)]
    public function onException(ExceptionEvent $event): void
    {
        if (!$this->container->resolved(SecurityManagerInterface::class)) {
            return;
        }

        $response = $this->container->get(SecurityManagerInterface::class)->handleException($event->getRequest(), $event->getThrowable());

        if ($response !== null) {
            $event->setResponse($response);
        }
    }
}