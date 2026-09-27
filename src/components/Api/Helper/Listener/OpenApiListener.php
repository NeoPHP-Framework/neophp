<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Helper\Listener;

use NeoPHP\Component\Api\Controller\OpenApiController;
use NeoPHP\Component\Api\Provider\ApiProvider;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Kernel\Event\RequestEvent;
use NeoPHP\Component\Routing\Contract\RoutingInterface;
use NeoPHP\Component\Routing\Route\Route;

class OpenApiListener
{
    public const ROUTE_JSON = 'api_doc_json';

    public const ROUTE_HTML = 'api_doc';

    protected bool $registered = false;

    public function __construct(protected ContainerInterface $container)
    {
    }

    #[AsListener(priority: 64)]
    public function onRequest(RequestEvent $event): void
    {
        if ($this->registered || !$this->container->has(ApiProvider::CONFIG_ID) || !$this->container->has(RoutingInterface::class)) {
            return;
        }

        $this->registered = true;
        $config = (array) ($this->container->get(ApiProvider::CONFIG_ID)['openapi']['route'] ?? []);

        if (!($config['enabled'] ?? false)) {
            return;
        }

        $path = '/' . trim((string) ($config['path'] ?? '/api/doc'), '/');
        $routes = $this->container->get(RoutingInterface::class);

        if (!$routes->getRoutes()->has(self::ROUTE_JSON)) {
            $routes->add(new Route(self::ROUTE_JSON, $path . '.json', OpenApiController::class . '::json', ['GET']));
        }

        if (!$routes->getRoutes()->has(self::ROUTE_HTML)) {
            $routes->add(new Route(self::ROUTE_HTML, $path, OpenApiController::class . '::html', ['GET']));
        }
    }
}