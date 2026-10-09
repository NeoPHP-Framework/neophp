<?php

declare(strict_types=1);

namespace NeoPHP\Component\Session\Helper\Event;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Kernel\Event\ResponseEvent;
use NeoPHP\Component\Session\SessionManagerInterface;

/**
 * @internal
 */
#[AsListener(priority: -200)]
class SessionListener
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if ($this->container->resolved(SessionManagerInterface::class)) {
            $this->container->get(SessionManagerInterface::class)->save();
        }
    }
}