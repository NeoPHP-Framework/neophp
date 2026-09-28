<?php

declare(strict_types=1);

namespace NeoPHP\Component\Http\Helper\Listener;

use NeoPHP\Component\Config\Contract\ConfigInterface;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Http\Security\SecurityHeaders;
use NeoPHP\Component\Kernel\Event\ResponseEvent;

class SecurityHeadersListener
{
    public const CONFIG_KEY = 'framework.app.security_headers';

    protected ?SecurityHeaders $headers = null;

    public function __construct(protected ContainerInterface $container)
    {
    }

    #[AsListener(priority: -60)]
    public function onResponse(ResponseEvent $event): void
    {
        $this->headers ??= new SecurityHeaders($this->container->has(ConfigInterface::class) ? (array) ($this->container->get(ConfigInterface::class)->get(self::CONFIG_KEY, []) ?? []) : []);
        $this->headers->apply($event->getRequest(), $event->getResponse());
    }
}