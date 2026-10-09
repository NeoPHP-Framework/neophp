<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Provider;

use NeoPHP\Component\Cache\CacheManagerInterface;
use NeoPHP\Component\Cache\Contract\CacheInterface;
use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Event\EventManagerInterface;
use NeoPHP\Component\HttpClient\Contract\TransportInterface;
use NeoPHP\Component\HttpClient\HttpClientManager;
use NeoPHP\Component\HttpClient\HttpClientManagerInterface;
use NeoPHP\Component\HttpClient\Transport\TransportFactory;
use NeoPHP\Component\Logger\Contract\LoggerInterface;

/**
 * @internal
 */
class HttpClientProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.http_client';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(TransportFactory::class, static fn (): TransportFactory => new TransportFactory());

        $container->singleton(TransportInterface::class, static fn (ContainerManagerInterface $container): TransportInterface => $container->get(TransportFactory::class)->create((string) (self::config($container)['transport'] ?? 'auto')));

        $container->singleton(HttpClientManagerInterface::class, static fn (ContainerManagerInterface $container): HttpClientManagerInterface => new HttpClientManager(
            $container->get(TransportInterface::class),
            $container->has(EventManagerInterface::class) ? $container->get(EventManagerInterface::class) : null,
            $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
            self::config($container),
            $container->has(CacheManagerInterface::class) ? static fn (?string $pool): CacheInterface => $container->get(CacheManagerInterface::class)->pool($pool) : null,
        ));

        $container->alias(HttpClientManager::class, HttpClientManagerInterface::class);
        $container->alias('http_client', HttpClientManagerInterface::class);
        $container->alias('http_client.transport', TransportInterface::class);

        foreach (array_keys((array) (self::config($container)['clients'] ?? [])) as $name) {
            $container->singleton('http_client.' . $name, static fn (ContainerManagerInterface $container): HttpClientManagerInterface => $container->get(HttpClientManagerInterface::class)->client((string) $name));
        }
    }

    public static function config(ContainerManagerInterface $container): array
    {
        return $container->has(ConfigManagerInterface::class) ? (array) $container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) : [];
    }
}