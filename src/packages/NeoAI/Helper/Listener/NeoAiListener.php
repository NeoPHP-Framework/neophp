<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Helper\Listener;

use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Kernel\Event\RequestEvent;
use NeoPHP\Component\Routing\Contract\RoutingInterface;
use NeoPHP\Component\Routing\Route\Route;
use NeoPHP\Package\NeoAI\Controller\NeoAiController;
use NeoPHP\Package\NeoAI\NeoAiManager;
use NeoPHP\Package\NeoAI\Provider\NeoAiProvider;

class NeoAiListener
{
    public const ROUTE_CHAT = '_neo_ai_chat';

    public function __construct(protected ContainerInterface $container)
    {
    }

    #[AsListener(priority: 2040)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$this->container->bound(NeoAiProvider::CONFIG_ID) || !$this->container->has(RoutingInterface::class)) {
            return;
        }

        $manager = $this->container->get(NeoAiManager::class);

        if (!$manager instanceof NeoAiManager || !$manager->isWebEnabled()) {
            return;
        }

        $routing = $this->container->get(RoutingInterface::class);

        if ($routing->getRoutes()->has(self::ROUTE_CHAT)) {
            return;
        }

        $routing->add(new Route(self::ROUTE_CHAT, $manager->getWebPath() . '/chat', NeoAiController::class . '::chat', ['POST']));
    }
}