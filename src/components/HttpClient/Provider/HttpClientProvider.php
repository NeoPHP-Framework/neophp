<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Provider;

use NeoPHP\Component\Config\Contract\ConfigInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Event\Contract\EventDispatcherInterface;
use NeoPHP\Component\HttpClient\Contract\HttpClientInterface;
use NeoPHP\Component\HttpClient\Contract\TransportInterface;
use NeoPHP\Component\HttpClient\HttpClientManager;
use NeoPHP\Component\HttpClient\Transport\TransportFactory;
use NeoPHP\Component\Logger\Contract\LoggerInterface;

class HttpClientProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.http_client';

    public function register(ContainerInterface $container): void
    {
        $container->singleton(TransportFactory::class, static fn (): TransportFactory => new TransportFactory());

        $container->singleton(TransportInterface::class, static fn (ContainerInterface $container): TransportInterface => $container->get(TransportFactory::class)->create((string) (self::config($container)['transport'] ?? 'auto')));

        $container->singleton(HttpClientInterface::class, static fn (ContainerInterface $container): HttpClientInterface => new HttpClientManager(
            $container->get(TransportInterface::class),
            $container->has(EventDispatcherInterface::class) ? $container->get(EventDispatcherInterface::class) : null,
            $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
            self::config($container),
        ));

        $container->alias(HttpClientManager::class, HttpClientInterface::class);
        $container->alias('http_client', HttpClientInterface::class);
        $container->alias('http_client.transport', TransportInterface::class);

        foreach (array_keys((array) (self::config($container)['clients'] ?? [])) as $name) {
            $container->singleton('http_client.' . $name, static fn (ContainerInterface $container): HttpClientInterface => $container->get(HttpClientInterface::class)->client((string) $name));
        }
    }

    public static function config(ContainerInterface $container): array
    {
        return $container->has(ConfigInterface::class) ? (array) $container->get(ConfigInterface::class)->get(self::CONFIG_KEY, []) : [];
    }
}