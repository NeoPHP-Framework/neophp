<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Helper\Event;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Kernel\Event\RequestEvent;
use NeoPHP\Component\Routing\Route\Route;
use NeoPHP\Component\Routing\RoutingManagerInterface;
use NeoPHP\Package\NeoAI\Controller\NeoAiController;
use NeoPHP\Package\NeoAI\NeoAiManager;
use NeoPHP\Package\NeoAI\Provider\NeoAiProvider;

/**
 * @internal
 */
class NeoAiListener
{
    public const ROUTE_CHAT = '_neo_ai_chat';

    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    #[AsListener(priority: 2040)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$this->container->bound(NeoAiProvider::CONFIG_ID) || !$this->container->has(RoutingManagerInterface::class)) {
            return;
        }

        $manager = $this->container->get(NeoAiManager::class);

        if (!$manager instanceof NeoAiManager || !$manager->isWebEnabled()) {
            return;
        }

        $routing = $this->container->get(RoutingManagerInterface::class);

        if ($routing->getRoutes()->has(self::ROUTE_CHAT)) {
            return;
        }

        $routing->add(new Route(self::ROUTE_CHAT, $manager->getWebPath() . '/chat', NeoAiController::class . '::chat', ['POST']));
    }
}