<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Helper\Listener;

use NeoPHP\Component\Api\Attribute\RateLimit as RateLimitAttribute;
use NeoPHP\Component\Api\Provider\ApiProvider;
use NeoPHP\Component\Api\RateLimiter\RateLimit;
use NeoPHP\Component\Api\RateLimiter\RateLimiterFactory;
use NeoPHP\Component\Api\RateLimiter\RequestKeyResolver;
use NeoPHP\Component\Api\Reflection\ControllerReflector;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Event\Attribute\AsListener;
use NeoPHP\Component\Kernel\Event\ControllerEvent;
use NeoPHP\Component\Kernel\Event\ResponseEvent;

class RateLimitListener
{
    public const ATTRIBUTE = '_rate_limit';

    public function __construct(protected ContainerInterface $container)
    {
    }

    #[AsListener(priority: 16)]
    public function onController(ControllerEvent $event): void
    {
        $attributes = ControllerReflector::attributes($event->getController(), RateLimitAttribute::class);

        if ($attributes === [] || !$this->container->has(RateLimiterFactory::class)) {
            return;
        }

        $request = $event->getRequest();
        $factory = $this->container->get(RateLimiterFactory::class);
        $keys = $this->container->get(RequestKeyResolver::class);
        $current = null;

        foreach ($attributes as $attribute) {
            if ($attribute->methods !== [] && !in_array($request->getMethod(), array_map('strtoupper', $attribute->methods), true)) {
                continue;
            }

            $limit = $factory->create($attribute->limiter, $keys->resolve($attribute->key, $request))->consume($attribute->cost);

            if (!$limit->isAccepted()) {
                $limit->ensureAccepted();
            }

            if ($current === null || $limit->getRemaining() < $current->getRemaining()) {
                $current = $limit;
            }
        }

        if ($current !== null) {
            $request->attributes->set(self::ATTRIBUTE, $current);
        }
    }

    #[AsListener(priority: -40)]
    public function onResponse(ResponseEvent $event): void
    {
        $limit = $event->getRequest()->attributes->get(self::ATTRIBUTE);

        if (!$limit instanceof RateLimit || $limit->getLimit() === PHP_INT_MAX || !$this->headers()) {
            return;
        }

        $response = $event->getResponse();

        foreach ($limit->getHeaders() as $name => $value) {
            if (!$response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }
    }

    protected function headers(): bool
    {
        return !$this->container->has(ApiProvider::CONFIG_ID) || (bool) ($this->container->get(ApiProvider::CONFIG_ID)['rate_limiter']['headers'] ?? true);
    }
}