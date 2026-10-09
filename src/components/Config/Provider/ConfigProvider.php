<?php

declare(strict_types=1);

namespace NeoPHP\Component\Config\Provider;

use NeoPHP\Component\Config\ConfigManager;
use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Package\Yaml\YamlManagerInterface;

/**
 * @internal
 */
class ConfigProvider extends AbstractProvider
{
    public const PARAMETERS_ID = 'kernel.parameters';

    public const EXCLUDED = ['routes.yaml', 'routes.yml', 'routes', 'services.yaml', 'services.yml'];

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(ConfigManagerInterface::class, static function (ContainerManagerInterface $container): ConfigManagerInterface {
            $parameters = $container->has(self::PARAMETERS_ID) ? (array) $container->get(self::PARAMETERS_ID) : [];
            $config = new ConfigManager($container->get(YamlManagerInterface::class), $parameters);
            $configPath = $config->get('kernel.config_path');

            if (is_string($configPath)) {
                $config->loadDirectory($configPath, self::EXCLUDED);
            }

            return $config;
        });

        $container->alias(ConfigManager::class, ConfigManagerInterface::class);
        $container->alias('config', ConfigManagerInterface::class);
    }
}