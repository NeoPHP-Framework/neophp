<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Event\EventManagerInterface;
use NeoPHP\Component\Logger\Contract\LoggerInterface;
use NeoPHP\Component\Mailer\Contract\TransportInterface;
use NeoPHP\Component\Mailer\MailerManager;
use NeoPHP\Component\Mailer\MailerManagerInterface;
use NeoPHP\Component\Mailer\Transport\TransportFactory;

/**
 * @internal
 */
class MailerProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.mailer';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(TransportFactory::class, static fn (ContainerManagerInterface $container): TransportFactory => new TransportFactory(
            $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : (string) getcwd(),
            $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
        ));

        $container->singleton(TransportInterface::class, static fn (ContainerManagerInterface $container): TransportInterface => $container->get(TransportFactory::class)->create((string) (self::config($container)['dsn'] ?? 'null://null')));

        $container->singleton(MailerManagerInterface::class, static fn (ContainerManagerInterface $container): MailerManagerInterface => new MailerManager(
            $container->get(TransportInterface::class),
            $container->has(EventManagerInterface::class) ? $container->get(EventManagerInterface::class) : null,
            self::config($container),
        ));

        $container->alias(MailerManager::class, MailerManagerInterface::class);
        $container->alias('mailer', MailerManagerInterface::class);
        $container->alias('mailer.transport', TransportInterface::class);
    }

    public static function config(ContainerManagerInterface $container): array
    {
        return $container->has(ConfigManagerInterface::class) ? (array) $container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) : [];
    }
}