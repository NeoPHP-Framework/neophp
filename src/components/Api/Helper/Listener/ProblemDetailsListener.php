<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Helper\Listener;

use NeoPHP\Component\Api\ProblemDetails\ProblemDetailsFactory;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Kernel\Event\ExceptionEvent;

class ProblemDetailsListener
{
    public function __construct(protected ContainerInterface $container)
    {
    }

    #[AsListener(priority: 4)]
    public function onException(ExceptionEvent $event): void
    {
        if (!$this->container->has(ProblemDetailsFactory::class)) {
            return;
        }

        $factory = $this->container->get(ProblemDetailsFactory::class);
        $request = $event->getRequest();

        if ($factory->supports($request)) {
            $event->setResponse($factory->createResponse($event->getThrowable(), $request));
        }
    }
}