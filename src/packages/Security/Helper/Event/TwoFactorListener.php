<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Helper\Event;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Component\Kernel\Event\RequestEvent;
use NeoPHP\Package\Security\Contract\TwoFactorUserInterface;
use NeoPHP\Package\Security\Event\LoginSuccessEvent;
use NeoPHP\Package\Security\Firewall\FirewallFactory;
use NeoPHP\Package\Security\Firewall\HttpUtils;
use NeoPHP\Package\Security\TwoFactor\TwoFactorManager;

/**
 * @internal
 */
class TwoFactorListener
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    #[AsListener]
    public function onLogin(LoginSuccessEvent $event): void
    {
        $manager = $this->container->get(TwoFactorManager::class);
        $user = $event->getUser();

        if (!$manager->isEnabled() || !$user instanceof TwoFactorUserInterface || $user->getTotpSecret() === null || $event->getAuthenticator() === 'programmatic') {
            return;
        }

        if (!$this->container->get(FirewallFactory::class)->get($event->getFirewall())->isStateless()) {
            $manager->start();
        }
    }

    #[AsListener(priority: 4)]
    public function onRequest(RequestEvent $event): void
    {
        $manager = $this->container->get(TwoFactorManager::class);
        $request = $event->getRequest();

        if (!$manager->isEnabled() || !$manager->isPending($request)) {
            return;
        }

        $http = $this->container->get(HttpUtils::class);

        if ($http->checkRequestPath($request, $manager->getPath())) {
            return;
        }

        $event->setResponse($request->wantsJson() || $request->isJson()
            ? new JsonResponse(['error' => 'Two-factor authentication required.'], 403)
            : $http->createRedirectResponse($manager->getPath()));
    }
}