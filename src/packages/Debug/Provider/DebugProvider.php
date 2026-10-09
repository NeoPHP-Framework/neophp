<?php

declare(strict_types=1);

namespace NeoPHP\Package\Debug\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Exception\ExceptionManager;
use NeoPHP\Package\Debug\DebugManager;
use NeoPHP\Package\Debug\DebugManagerInterface;

/**
 * @internal
 */
class DebugProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'packages.debug';

    public const EXCEPTION_DEPTH = 3;

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(DebugManagerInterface::class, static fn (ContainerManagerInterface $container): DebugManagerInterface => new DebugManager(self::configure($container)));

        $container->alias(DebugManager::class, DebugManagerInterface::class);
        $container->alias('debug', DebugManagerInterface::class);
    }

    public function boot(ContainerManagerInterface $container): void
    {
        $debug = $container->get(DebugManagerInterface::class);
        DebugManager::setInstance($debug);

        if ($debug->isEnabled() && $container->has(ExceptionManager::class)) {
            $container->get(ExceptionManager::class)->setDumper(static fn (mixed $value): string => $debug->toHtml($value, null, self::EXCEPTION_DEPTH));
        }
    }

    public static function configure(ContainerManagerInterface $container): array
    {
        $config = $container->has(ConfigManagerInterface::class) ? (array) ($container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) ?? []) : [];
        $debug = $container->has('kernel.debug') && (bool) $container->get('kernel.debug');

        return [
            'enabled' => filter_var($config['enabled'] ?? $debug, FILTER_VALIDATE_BOOLEAN),
            'max_depth' => (int) ($config['max_depth'] ?? DebugManager::DEFAULT_OPTIONS['max_depth']),
            'max_items' => (int) ($config['max_items'] ?? DebugManager::DEFAULT_OPTIONS['max_items']),
            'max_string' => (int) ($config['max_string'] ?? DebugManager::DEFAULT_OPTIONS['max_string']),
            'expand_depth' => (int) ($config['expand_depth'] ?? DebugManager::DEFAULT_OPTIONS['expand_depth']),
            'root_path' => $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : null,
        ];
    }
}