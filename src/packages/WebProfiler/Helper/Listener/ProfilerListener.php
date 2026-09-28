<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Helper\Listener;

use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Kernel\Event\ControllerEvent;
use NeoPHP\Component\Kernel\Event\ExceptionEvent;
use NeoPHP\Component\Kernel\Event\RequestEvent;
use NeoPHP\Component\Kernel\Event\ResponseEvent;
use NeoPHP\Component\Routing\Contract\RoutingInterface;
use NeoPHP\Component\Routing\Route\Route;
use NeoPHP\Package\WebProfiler\Controller\ProfilerController;
use NeoPHP\Package\WebProfiler\Profiler;
use NeoPHP\Package\WebProfiler\Provider\WebProfilerProvider;
use NeoPHP\Package\WebProfiler\Renderer\ToolbarInjector;
use Throwable;

class ProfilerListener
{
    public const ROUTE_INDEX = '_profiler_index';

    public const ROUTE_LATEST = '_profiler_latest';

    public const ROUTE_JSON = '_profiler_json';

    public const ROUTE_SHOW = '_profiler_show';

    public const ROUTE_TOOLBAR = '_profiler_toolbar';

    public const TOKEN_REQUIREMENT = '[a-zA-Z0-9]+';

    public const HEADER_TOKEN = 'X-Debug-Token';

    public const HEADER_LINK = 'X-Debug-Token-Link';

    public function __construct(protected ContainerInterface $container)
    {
    }

    #[AsListener(priority: 2048)]
    public function onRequest(RequestEvent $event): void
    {
        $profiler = $this->profiler();

        if ($profiler === null || !$profiler->isAllowed($event->getRequest())) {
            return;
        }

        $profiler->setBasePath($event->getRequest()->getBasePath());
        $this->registerRoutes($profiler);
        $stopwatch = $profiler->getStopwatch();

        if ($stopwatch->getEvents() !== []) {
            $stopwatch->reset();
        } else {
            $stopwatch->start('bootstrap', 'kernel', $stopwatch->getOrigin());
            $stopwatch->stop('bootstrap');
        }

        $profiler->setException(null);
        $stopwatch->start('kernel.request', 'kernel');
    }

    #[AsListener(priority: -2048)]
    public function onController(ControllerEvent $event): void
    {
        $profiler = $this->profiler();

        if ($profiler === null) {
            return;
        }

        $profiler->getStopwatch()->stop('kernel.request');
        $profiler->getStopwatch()->start('controller', 'controller');
    }

    #[AsListener(priority: 2048)]
    public function onException(ExceptionEvent $event): void
    {
        $this->profiler()?->setException($event->getThrowable());
    }

    #[AsListener(priority: 2048)]
    public function onResponseStart(ResponseEvent $event): void
    {
        $stopwatch = $this->profiler()?->getStopwatch();

        if ($stopwatch === null) {
            return;
        }

        foreach (['kernel.request', 'controller'] as $name) {
            if ($stopwatch->isStarted($name)) {
                $stopwatch->stop($name);
            }
        }

        $stopwatch->start('kernel.response', 'kernel');
    }

    #[AsListener(priority: -2048)]
    public function onResponse(ResponseEvent $event): void
    {
        $profiler = $this->profiler();

        if ($profiler === null) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $profiler->getStopwatch()->stop('kernel.response');

        if (!$profiler->isAllowed($request) || $profiler->isExcluded($request)) {
            return;
        }

        try {
            $profile = $profiler->collect($request, $response);
            $profiler->save($profile);
        } catch (Throwable) {
            return;
        }

        $response->headers->set(self::HEADER_TOKEN, $profile->getToken());
        $response->headers->set(self::HEADER_LINK, $request->getSchemeAndHttpHost() . $profiler->getProfileUrl($profile->getToken()));

        if ($profiler->isToolbarEnabled()) {
            $this->container->get(ToolbarInjector::class)->inject($request, $response, $profile->getToken());
        }
    }

    protected function profiler(): ?Profiler
    {
        if (!$this->container->bound(WebProfilerProvider::CONFIG_ID) || !($this->container->get(WebProfilerProvider::CONFIG_ID)['enabled'] ?? false)) {
            return null;
        }

        $profiler = $this->container->get(Profiler::class);

        return $profiler instanceof Profiler && $profiler->isEnabled() ? $profiler : null;
    }

    protected function registerRoutes(Profiler $profiler): void
    {
        if (!$this->container->has(RoutingInterface::class)) {
            return;
        }

        $routing = $this->container->get(RoutingInterface::class);

        if ($routing->getRoutes()->has(self::ROUTE_INDEX)) {
            return;
        }

        $path = $profiler->getPath();
        $token = ['token' => self::TOKEN_REQUIREMENT];

        $routing->add(new Route(self::ROUTE_INDEX, $path, ProfilerController::class . '::index', ['GET']));
        $routing->add(new Route(self::ROUTE_LATEST, $path . '/latest', ProfilerController::class . '::latest', ['GET']));
        $routing->add(new Route(self::ROUTE_JSON, $path . '/{token}.json', ProfilerController::class . '::json', ['GET'], $token));
        $routing->add(new Route(self::ROUTE_SHOW, $path . '/{token}', ProfilerController::class . '::show', ['GET'], $token));
        $routing->add(new Route(self::ROUTE_TOOLBAR, $profiler->getToolbarPath() . '/{token}', ProfilerController::class . '::toolbar', ['GET'], $token));
    }
}