<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Provider;

use DateTimeZone;
use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Kernel\Exception\KernelException;
use NeoPHP\Component\Kernel\KernelManager;
use NeoPHP\Component\Kernel\KernelManagerInterface;

/**
 * @internal
 */
class KernelProvider extends AbstractProvider
{
    public const TIMEZONE_KEY = 'framework.app.timezone';

    public function register(ContainerManagerInterface $container): void
    {
        if ($container->bound(KernelManagerInterface::class) && !$container->bound(KernelManager::class)) {
            $container->alias(KernelManager::class, KernelManagerInterface::class);
        }
    }

    public function boot(ContainerManagerInterface $container): void
    {
        if (!$container->has(ConfigManagerInterface::class)) {
            return;
        }

        $timezone = (string) ($container->get(ConfigManagerInterface::class)->get(self::TIMEZONE_KEY, '') ?? '');

        if ($timezone === '') {
            return;
        }

        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new KernelException('Invalid timezone "{timezone}" in "{key}".', 0, null, [
                'timezone' => $timezone,
                'key' => self::TIMEZONE_KEY,
            ]);
        }

        date_default_timezone_set($timezone);
    }
}