<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Provider;

use NeoPHP\Component\Cache\CacheManagerInterface;
use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Package\QrCode\QrCodeManager;
use NeoPHP\Package\QrCode\QrCodeManagerInterface;

/**
 * @internal
 */
class QrCodeProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'packages.qr_code';

    public const SECRET_KEY = 'framework.app.secret';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(QrCodeManagerInterface::class, static function (ContainerManagerInterface $container): QrCodeManagerInterface {
            $settings = $container->has(ConfigManagerInterface::class) ? $container->get(ConfigManagerInterface::class) : null;
            $config = $settings instanceof ConfigManagerInterface ? (array) ($settings->get(self::CONFIG_KEY, []) ?? []) : [];
            $secret = $settings instanceof ConfigManagerInterface ? $settings->get(self::SECRET_KEY) : null;
            $secret ??= $_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? '';
            $pool = isset($config['cache_pool']) && $config['cache_pool'] !== '' ? (string) $config['cache_pool'] : null;

            return new QrCodeManager(
                $config,
                $container->has(CacheManagerInterface::class) ? $container->get(CacheManagerInterface::class)->pool($pool) : null,
                (string) $secret,
                static fn (): string => $container->has(Request::class) ? $container->get(Request::class)->getBasePath() : '',
            );
        });

        $container->alias(QrCodeManager::class, QrCodeManagerInterface::class);
        $container->alias('qr_code', QrCodeManagerInterface::class);
    }
}