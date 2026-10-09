<?php

declare(strict_types=1);

namespace NeoPHP\Component\Flash\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Flash\FlashManager;
use NeoPHP\Component\Flash\FlashManagerInterface;
use NeoPHP\Component\Flash\Trace\FlashTrace;
use NeoPHP\Component\Session\SessionManagerInterface;

/**
 * @internal
 */
class FlashProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.app.flash';

    public const PROFILER_CONFIG_ID = 'web_profiler.config';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(FlashManagerInterface::class, static function (ContainerManagerInterface $container): FlashManagerInterface {
            $flash = new FlashManager($container->get(SessionManagerInterface::class), self::key($container));

            return self::profilingEnabled($container) ? $flash->setTrace(new FlashTrace()) : $flash;
        });

        $container->alias(FlashManager::class, FlashManagerInterface::class);
    }

    public static function key(ContainerManagerInterface $container): string
    {
        $config = $container->has(ConfigManagerInterface::class) ? (array) $container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) : [];
        $key = $config['key'] ?? null;

        return is_string($key) && $key !== '' ? $key : FlashManager::DEFAULT_KEY;
    }

    protected static function profilingEnabled(ContainerManagerInterface $container): bool
    {
        if (!$container->bound(self::PROFILER_CONFIG_ID) && !$container->has(self::PROFILER_CONFIG_ID)) {
            return false;
        }

        $config = $container->get(self::PROFILER_CONFIG_ID);

        return is_array($config) && (bool) ($config['enabled'] ?? false);
    }
}