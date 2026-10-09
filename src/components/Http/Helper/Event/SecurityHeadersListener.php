<?php

declare(strict_types=1);

namespace NeoPHP\Component\Http\Helper\Event;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Http\Security\SecurityHeaders;
use NeoPHP\Component\Kernel\Event\ResponseEvent;

/**
 * @internal
 */
class SecurityHeadersListener
{
    public const CONFIG_KEY = 'framework.app.security_headers';

    protected ?SecurityHeaders $headers = null;

    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    #[AsListener(priority: -60)]
    public function onResponse(ResponseEvent $event): void
    {
        $this->headers ??= new SecurityHeaders($this->container->has(ConfigManagerInterface::class) ? (array) ($this->container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) ?? []) : []);
        $this->headers->apply($event->getRequest(), $event->getResponse());
    }
}