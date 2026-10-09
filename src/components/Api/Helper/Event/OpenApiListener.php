<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Helper\Event;

use NeoPHP\Component\Api\Controller\OpenApiController;
use NeoPHP\Component\Api\Provider\ApiProvider;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Kernel\Event\RequestEvent;
use NeoPHP\Component\Routing\Route\Route;
use NeoPHP\Component\Routing\RoutingManagerInterface;

/**
 * @internal
 */
class OpenApiListener
{
    public const ROUTE_JSON = 'api_doc_json';

    public const ROUTE_HTML = 'api_doc';

    protected bool $registered = false;

    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    #[AsListener(priority: 64)]
    public function onRequest(RequestEvent $event): void
    {
        if ($this->registered || !$this->container->has(ApiProvider::CONFIG_ID) || !$this->container->has(RoutingManagerInterface::class)) {
            return;
        }

        $this->registered = true;
        $config = (array) ($this->container->get(ApiProvider::CONFIG_ID)['openapi']['route'] ?? []);

        if (!($config['enabled'] ?? false)) {
            return;
        }

        $path = '/' . trim((string) ($config['path'] ?? '/api/doc'), '/');
        $routes = $this->container->get(RoutingManagerInterface::class);

        if (!$routes->getRoutes()->has(self::ROUTE_JSON)) {
            $routes->add(new Route(self::ROUTE_JSON, $path . '.json', OpenApiController::class . '::json', ['GET']));
        }

        if (!$routes->getRoutes()->has(self::ROUTE_HTML)) {
            $routes->add(new Route(self::ROUTE_HTML, $path, OpenApiController::class . '::html', ['GET']));
        }
    }
}