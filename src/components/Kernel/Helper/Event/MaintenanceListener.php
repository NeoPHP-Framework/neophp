<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Helper\Event;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Kernel\Event\RequestEvent;
use NeoPHP\Component\Kernel\Maintenance\MaintenanceMode;

/**
 * @internal
 */
class MaintenanceListener
{
    public const TEMPLATE = 'maintenance.html';

    public const DEFAULT_MESSAGE = 'The application is under maintenance. Please try again later.';

    public const HEALTH_PATH_KEY = 'framework.app.health.path';

    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    #[AsListener(priority: 2000)]
    public function onRequest(RequestEvent $event): void
    {
        $maintenance = new MaintenanceMode((string) $this->container->get('kernel.root_path'));

        if (!$maintenance->isEnabled()) {
            return;
        }

        $data = $maintenance->read();
        $request = $event->getRequest();

        if ($this->isHealthRequest($request) || ($data['allowed_ips'] !== [] && $request->isClientIpIn($data['allowed_ips']))) {
            return;
        }

        $event->setResponse($this->response($request, $data['message'] !== '' ? $data['message'] : self::DEFAULT_MESSAGE, $data['retry']));
    }

    protected function isHealthRequest(Request $request): bool
    {
        if (!$this->container->has(ConfigManagerInterface::class)) {
            return false;
        }

        return $request->getPath() === (string) $this->container->get(ConfigManagerInterface::class)->get(self::HEALTH_PATH_KEY, '/health');
    }

    protected function response(Request $request, string $message, int $retry): Response
    {
        $headers = ['Retry-After' => (string) $retry, 'Cache-Control' => 'no-store'];

        if ($request->wantsJson() || $request->isJson()) {
            return new JsonResponse(['error' => ['status' => 503, 'message' => $message]], 503, $headers);
        }

        return new Response($this->html($message), 503, $headers + ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    protected function html(string $message): string
    {
        $template = (string) $this->container->get('kernel.templates_path') . DIRECTORY_SEPARATOR . self::TEMPLATE;

        if (is_file($template)) {
            return (string) file_get_contents($template);
        }

        $message = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Maintenance</title>
            <style>
            body{font-family:system-ui,sans-serif;background:#f6f7f9;color:#1d2433;display:grid;place-items:center;min-height:100vh;margin:0}
            main{text-align:center;padding:2rem}h1{font-size:2.5rem;margin:0 0 .5rem}p{color:#5b6475}
            </style>
            </head>
            <body><main><h1>Maintenance</h1><p>{$message}</p></main></body>
            </html>
            HTML;
    }
}