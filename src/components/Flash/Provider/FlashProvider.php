<?php

declare(strict_types=1);

namespace NeoPHP\Component\Flash\Provider;

use NeoPHP\Component\Config\Contract\ConfigInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Flash\Contract\FlashInterface;
use NeoPHP\Component\Flash\FlashManager;
use NeoPHP\Component\Flash\Trace\FlashTrace;
use NeoPHP\Component\Session\Contract\SessionInterface;

class FlashProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.app.flash';

    public const PROFILER_CONFIG_ID = 'web_profiler.config';

    public function register(ContainerInterface $container): void
    {
        $container->singleton(FlashInterface::class, static function (ContainerInterface $container): FlashInterface {
            $config = $container->has(ConfigInterface::class) ? (array) $container->get(ConfigInterface::class)->get(self::CONFIG_KEY, []) : [];

            $flash = new FlashManager($container->get(SessionInterface::class), (string) ($config['key'] ?? '_flashes'));

            return self::profilingEnabled($container) ? $flash->setTrace(new FlashTrace()) : $flash;
        });

        $container->alias(FlashManager::class, FlashInterface::class);
    }

    protected static function profilingEnabled(ContainerInterface $container): bool
    {
        if (!$container->bound(self::PROFILER_CONFIG_ID) && !$container->has(self::PROFILER_CONFIG_ID)) {
            return false;
        }

        $config = $container->get(self::PROFILER_CONFIG_ID);

        return is_array($config) && (bool) ($config['enabled'] ?? false);
    }
}