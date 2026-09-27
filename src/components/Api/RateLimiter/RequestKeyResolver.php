<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\RateLimiter;

use NeoPHP\Component\Api\Exception\RateLimiterException;
use NeoPHP\Component\Api\RateLimiter\Contract\KeyResolverInterface;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Package\Security\Contract\SecurityInterface;

class RequestKeyResolver
{
    public function __construct(protected ContainerInterface $container)
    {
    }

    public function resolve(string $key, Request $request): string
    {
        $ip = 'ip:' . ($request->getClientIp() ?? 'unknown');

        return match (true) {
            $key === '' || $key === 'ip' => $ip,
            $key === 'user' => $this->user() ?? $ip,
            $key === 'route' => 'route:' . (string) $request->attributes->get('_route', $request->getPath()) . '|' . $ip,
            str_starts_with($key, 'header:') => 'header:' . (string) ($request->headers->get(substr($key, 7)) ?? $ip),
            str_starts_with($key, 'attribute:') => 'attribute:' . $this->scalar($request->attributes->get(substr($key, 10)), $ip),
            str_starts_with($key, 'query:') => 'query:' . $this->scalar($request->query->get(substr($key, 6)), $ip),
            class_exists($key) => $this->custom($key, $request) ?? $ip,
            default => throw new RateLimiterException('Unknown rate limit key "{key}": use ip, user, route, header:<name>, attribute:<name>, query:<name> or a class implementing {interface}.', 0, null, [
                'key' => $key,
                'interface' => KeyResolverInterface::class,
            ]),
        };
    }

    protected function user(): ?string
    {
        if (!$this->container->has(SecurityInterface::class)) {
            return null;
        }

        $user = $this->container->get(SecurityInterface::class)->getUser();

        return $user === null ? null : 'user:' . $user->getUserIdentifier();
    }

    protected function custom(string $class, Request $request): ?string
    {
        $resolver = $this->container->get($class);

        if (!$resolver instanceof KeyResolverInterface) {
            throw new RateLimiterException('The rate limit key resolver "{class}" must implement {interface}.', 0, null, [
                'class' => $class,
                'interface' => KeyResolverInterface::class,
            ]);
        }

        $key = $resolver->resolve($request);

        return $key === null || $key === '' ? null : 'custom:' . $class . ':' . $key;
    }

    protected function scalar(mixed $value, string $fallback): string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : $fallback;
    }
}