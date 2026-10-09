<?php

declare(strict_types=1);

namespace NeoPHP\Package\Tailwind\Provider;

use NeoPHP\Component\Asset\AssetManagerInterface;
use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Package\Tailwind\TailwindManager;
use NeoPHP\Package\Tailwind\TailwindManagerInterface;

/**
 * @internal
 */
class TailwindProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'packages.tailwind';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(TailwindManagerInterface::class, static function (ContainerManagerInterface $container): TailwindManagerInterface {
            $rootPath = $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : (string) getcwd();
            $sourcePath = $container->has(AssetManagerInterface::class) ? $container->get(AssetManagerInterface::class)->getSourcePath() : null;

            return new TailwindManager($rootPath, self::config($container), $sourcePath);
        });

        $container->alias(TailwindManager::class, TailwindManagerInterface::class);
    }

    public function boot(ContainerManagerInterface $container): void
    {
        $input = self::config($container)['input'] ?? null;

        if (!is_string($input) || $input === '' || !$container->has(AssetManagerInterface::class)) {
            return;
        }

        $tailwind = $container->get(TailwindManagerInterface::class);
        $container->get(AssetManagerInterface::class)->setSourceFile((string) $tailwind->getInput(), $tailwind->getOutputFile((string) $tailwind->getInput()));
    }

    protected static function config(ContainerManagerInterface $container): array
    {
        return $container->has(ConfigManagerInterface::class) ? (array) ($container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) ?? []) : [];
    }
}