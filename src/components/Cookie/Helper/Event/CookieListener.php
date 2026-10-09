<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cookie\Helper\Event;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Cookie\CookieManagerInterface;
use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Kernel\Event\ResponseEvent;

/**
 * @internal
 */
#[AsListener(priority: -100)]
class CookieListener
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if ($this->container->resolved(CookieManagerInterface::class)) {
            $this->container->get(CookieManagerInterface::class)->apply($event->getResponse());
        }
    }
}