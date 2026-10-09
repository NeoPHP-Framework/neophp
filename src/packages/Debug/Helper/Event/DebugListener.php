<?php

declare(strict_types=1);

namespace NeoPHP\Package\Debug\Helper\Event;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Kernel\Event\ResponseEvent;
use NeoPHP\Package\Debug\DebugManagerInterface;

/**
 * @internal
 */
#[AsListener(priority: -150)]
class DebugListener
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$this->container->resolved(DebugManagerInterface::class)) {
            return;
        }

        $debug = $this->container->get(DebugManagerInterface::class);

        if ($debug->hasPending()) {
            $debug->injectInto($event->getResponse());
        }
    }
}