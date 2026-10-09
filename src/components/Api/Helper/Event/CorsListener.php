<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Helper\Event;

use NeoPHP\Component\Api\Cors\CorsManager;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Kernel\Event\RequestEvent;
use NeoPHP\Component\Kernel\Event\ResponseEvent;
use NeoPHP\Component\Routing\RoutingManagerInterface;
use Throwable;

/**
 * @internal
 */
class CorsListener
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    #[AsListener(priority: 32)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$this->container->has(CorsManager::class)) {
            return;
        }

        $cors = $this->container->get(CorsManager::class);
        $request = $event->getRequest();

        if (!$cors->isPreflight($request)) {
            return;
        }

        $options = $cors->resolve($request, $this->controller($request->getPath(), (string) $request->headers->get('Access-Control-Request-Method', '')));

        if ($options === null) {
            return;
        }

        $request->attributes->set(CorsManager::HANDLED_ATTRIBUTE, true);
        $event->setResponse($cors->preflight($request, $options));
    }

    #[AsListener(priority: -50)]
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if ($request->attributes->get(CorsManager::HANDLED_ATTRIBUTE) === true || !$this->container->has(CorsManager::class)) {
            return;
        }

        $cors = $this->container->get(CorsManager::class);
        $options = $cors->resolve($request, $request->attributes->get('_controller'));

        if ($options !== null) {
            $cors->apply($request, $event->getResponse(), $options);
        }
    }

    protected function controller(string $path, string $method): mixed
    {
        if ($method === '' || !$this->container->has(RoutingManagerInterface::class)) {
            return null;
        }

        try {
            return $this->container->get(RoutingManagerInterface::class)->match($method, $path)->getController();
        } catch (Throwable) {
            return null;
        }
    }
}