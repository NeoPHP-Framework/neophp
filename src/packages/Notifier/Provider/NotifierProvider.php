<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Database\DatabaseManagerInterface;
use NeoPHP\Component\HttpClient\HttpClientManagerInterface;
use NeoPHP\Component\Logger\Contract\LoggerInterface;
use NeoPHP\Component\Logger\LoggerManagerInterface;
use NeoPHP\Component\Mailer\MailerManagerInterface;
use NeoPHP\Package\Mercure\MercureManagerInterface;
use NeoPHP\Package\Notifier\Channel\DatabaseChannel;
use NeoPHP\Package\Notifier\Channel\EmailChannel;
use NeoPHP\Package\Notifier\Channel\MercureChannel;
use NeoPHP\Package\Notifier\Channel\PushChannel;
use NeoPHP\Package\Notifier\Contract\ChannelInterface;
use NeoPHP\Package\Notifier\Exception\NotifierException;
use NeoPHP\Package\Notifier\NotifierManager;
use NeoPHP\Package\Notifier\NotifierManagerInterface;
use NeoPHP\Package\Notifier\Storage\NotificationStore;
use NeoPHP\Package\Notifier\WebPush\Vapid;
use NeoPHP\Package\Queue\Contract\MessageBusInterface;

/**
 * @internal
 */
class NotifierProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'packages.notifier';

    public const DATABASE_CONFIG_KEY = 'framework.database';

    public const URL_KEY = 'framework.app.url';

    public const LOG_CHANNEL = 'notifier';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(NotifierManagerInterface::class, static function (ContainerManagerInterface $container): NotifierManagerInterface {
            $config = self::config($container);
            $store = self::store($container, $config);
            $vapid = self::vapid($config);
            $topic = (string) ($config['mercure']['topic'] ?? 'notifications/{id}');
            $settings = $container->has(ConfigManagerInterface::class) ? $container->get(ConfigManagerInterface::class) : null;
            $baseUrl = $settings instanceof ConfigManagerInterface ? (string) ($settings->get(self::URL_KEY) ?? '') : '';
            $channels = [
                new DatabaseChannel($store),
                new MercureChannel($container->has(MercureManagerInterface::class) ? $container->get(MercureManagerInterface::class) : null, $topic),
                new PushChannel($store, $container->has(HttpClientManagerInterface::class) ? $container->get(HttpClientManagerInterface::class) : null, $vapid, (int) ($config['push']['ttl'] ?? 86400)),
                new EmailChannel($container->has(MailerManagerInterface::class) ? $container->get(MailerManagerInterface::class) : null, isset($config['email']['from']) ? (string) $config['email']['from'] : null, $baseUrl),
            ];

            foreach ((array) ($config['custom_channels'] ?? []) as $class) {
                $channel = $container->get((string) $class);

                if (!$channel instanceof ChannelInterface) {
                    throw new NotifierException('The notification channel "{class}" must implement {interface}.', 0, null, ['class' => $class, 'interface' => ChannelInterface::class]);
                }

                $channels[] = $channel;
            }

            return new NotifierManager(
                $channels,
                (array) ($config['channel_policy'] ?? []),
                $store,
                $container->has(MessageBusInterface::class) ? $container->get(MessageBusInterface::class) : null,
                self::logger($container),
                $vapid,
                $topic,
            );
        });

        $container->alias(NotifierManager::class, NotifierManagerInterface::class);
        $container->alias('notifier', NotifierManagerInterface::class);
    }

    public static function config(ContainerManagerInterface $container): array
    {
        return $container->has(ConfigManagerInterface::class) ? (array) ($container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) ?? []) : [];
    }

    protected static function store(ContainerManagerInterface $container, array $config): ?NotificationStore
    {
        $settings = $container->has(ConfigManagerInterface::class) ? $container->get(ConfigManagerInterface::class) : null;
        $database = $settings instanceof ConfigManagerInterface ? (array) ($settings->get(self::DATABASE_CONFIG_KEY, []) ?? []) : [];

        if (!$container->has(DatabaseManagerInterface::class) || (array) ($database['connections'] ?? []) === []) {
            return null;
        }

        $connection = $config['database']['connection'] ?? null;

        return new NotificationStore(
            $container->get(DatabaseManagerInterface::class)->connection(is_string($connection) && $connection !== '' ? $connection : null),
            (bool) ($config['database']['auto_setup'] ?? true),
        );
    }

    protected static function vapid(array $config): ?Vapid
    {
        $push = (array) ($config['push'] ?? []);
        $public = (string) ($push['public_key'] ?? '');
        $private = (string) ($push['private_key'] ?? '');

        if ($public === '' || $private === '') {
            return null;
        }

        return new Vapid($public, $private, (string) ($push['subject'] ?? ''));
    }

    protected static function logger(ContainerManagerInterface $container): ?LoggerInterface
    {
        if (!$container->has(LoggerInterface::class)) {
            return null;
        }

        $logger = $container->get(LoggerInterface::class);

        return $logger instanceof LoggerManagerInterface && $logger->hasChannel(self::LOG_CHANNEL) ? $logger->channel(self::LOG_CHANNEL) : $logger;
    }
}