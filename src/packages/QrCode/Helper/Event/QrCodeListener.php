<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Helper\Event;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Kernel\Event\RequestEvent;
use NeoPHP\Component\Kernel\Event\ResponseEvent;
use NeoPHP\Component\Routing\Route\Route;
use NeoPHP\Component\Routing\RoutingManagerInterface;
use NeoPHP\Package\QrCode\Controller\QrCodeRouteController;
use NeoPHP\Package\QrCode\QrCodeManagerInterface;

/**
 * @internal
 */
class QrCodeListener
{
    public const ROUTE_IMAGE = 'qr_code_image';

    public const ROUTE_SCRIPT = 'qr_code_reader_script';

    public const ROUTE_READ = 'qr_code_read';

    protected bool $registered = false;

    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    #[AsListener(priority: 64)]
    public function onRequest(RequestEvent $event): void
    {
        if ($this->registered || !$this->container->has(RoutingManagerInterface::class) || !$this->container->has(QrCodeManagerInterface::class)) {
            return;
        }

        $this->registered = true;
        $manager = $this->container->get(QrCodeManagerInterface::class);
        $routing = $this->container->get(RoutingManagerInterface::class);
        $path = $manager->getPath();

        if (!$routing->getRoutes()->has(self::ROUTE_SCRIPT)) {
            $routing->add(new Route(self::ROUTE_SCRIPT, $path . '/reader.js', QrCodeRouteController::class . '::script', ['GET']));
        }

        if (!$routing->getRoutes()->has(self::ROUTE_IMAGE)) {
            $routing->add(new Route(self::ROUTE_IMAGE, $path . '/{key}.{format}', QrCodeRouteController::class . '::image', ['GET'], ['key' => '[a-f0-9]{32}', 'format' => 'svg|png']));
        }

        if (($manager->getConfig()['reader']['route'] ?? false) && !$routing->getRoutes()->has(self::ROUTE_READ)) {
            $routing->add(new Route(self::ROUTE_READ, $path . '/read', QrCodeRouteController::class . '::read', ['POST']));
        }
    }

    /**
     * Runs after the security headers (priority -60): only the camera directive changes, on the pages of the reader.
     */
    #[AsListener(priority: -70)]
    public function onResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        $policy = $response->headers->get('Permissions-Policy');

        if ($policy === null || !str_contains($policy, 'camera=') || !str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'html')) {
            return;
        }

        if (!str_contains($response->getContent(), '<neo-qr-reader')) {
            return;
        }

        $response->headers->set('Permissions-Policy', (string) preg_replace('/camera=\([^)]*\)/', 'camera=(self)', $policy));
    }
}