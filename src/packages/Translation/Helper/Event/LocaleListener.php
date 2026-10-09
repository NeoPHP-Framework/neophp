<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Helper\Event;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Kernel\Event\ControllerEvent;
use NeoPHP\Component\Kernel\Event\RequestEvent;
use NeoPHP\Component\Session\SessionManagerInterface;
use NeoPHP\Package\Translation\Locale\LocaleDetector;
use NeoPHP\Package\Translation\TranslationManagerInterface;

/**
 * @internal
 */
class LocaleListener
{
    public const SOURCE_ATTRIBUTE = '_locale_source';

    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    #[AsListener(priority: 64)]
    public function onRequest(RequestEvent $event): void
    {
        $this->apply($event->getRequest(), null);
    }

    #[AsListener(priority: 64)]
    public function onController(ControllerEvent $event): void
    {
        $this->apply($event->getRequest(), $event->getParameters());
    }

    protected function apply(Request $request, ?array $routeParameters): void
    {
        if (!$this->container->has(TranslationManagerInterface::class)) {
            return;
        }

        $translator = $this->container->get(TranslationManagerInterface::class);

        if (count($translator->getLocales()) < 2 && !isset($routeParameters[TranslationManagerInterface::ATTRIBUTE])) {
            $request->attributes->set(TranslationManagerInterface::ATTRIBUTE, $translator->getLocale());

            return;
        }

        $session = $this->container->has(SessionManagerInterface::class) ? $this->container->get(SessionManagerInterface::class) : null;
        $detected = $this->container->get(LocaleDetector::class)->detect($request, $routeParameters, $session);
        [$locale, $source] = $detected ?? [$translator->getDefaultLocale(), 'default'];

        $translator->setLocale($locale);
        $request->attributes->set(TranslationManagerInterface::ATTRIBUTE, $translator->getLocale());
        $request->attributes->set(self::SOURCE_ATTRIBUTE, $source);
    }
}