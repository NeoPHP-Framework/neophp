<?php

declare(strict_types=1);

namespace NeoPHP\Component\Logger\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Logger\Contract\LoggerInterface;
use NeoPHP\Component\Logger\LoggerManager;
use NeoPHP\Component\Logger\LoggerManagerInterface;

/**
 * @internal
 */
class LoggerProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.logger';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(LoggerManagerInterface::class, static function (ContainerManagerInterface $container): LoggerManagerInterface {
            $config = $container->has(ConfigManagerInterface::class) ? (array) $container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) : [];
            $rootPath = $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : (string) getcwd();

            return LoggerManager::fromConfig($config, $rootPath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log');
        });

        $container->alias(LoggerManager::class, LoggerManagerInterface::class);
        $container->alias(LoggerInterface::class, LoggerManagerInterface::class);
    }
}