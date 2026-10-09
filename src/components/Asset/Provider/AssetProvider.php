<?php

declare(strict_types=1);

namespace NeoPHP\Component\Asset\Provider;

use NeoPHP\Component\Asset\AssetManager;
use NeoPHP\Component\Asset\AssetManagerInterface;
use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Http\Request\Request;

/**
 * @internal
 */
class AssetProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.asset';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(AssetManagerInterface::class, static function (ContainerManagerInterface $container): AssetManagerInterface {
            $config = $container->has(ConfigManagerInterface::class) ? (array) $container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) : [];
            $rootPath = $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : (string) getcwd();
            $publicPath = $container->has('kernel.public_path') ? (string) $container->get('kernel.public_path') : $rootPath . '/public';

            $asset = AssetManager::fromConfig($config, [
                'source_path' => $rootPath . '/assets',
                'build_path' => $publicPath . '/builds',
                'public_url' => '/builds',
                'auto_compile' => $container->has('kernel.debug') && (bool) $container->get('kernel.debug'),
            ]);

            return $asset->setBasePath(static fn (): string => $container->has(Request::class) ? $container->get(Request::class)->getBasePath() : '');
        });

        $container->alias(AssetManager::class, AssetManagerInterface::class);
    }
}