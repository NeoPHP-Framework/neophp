<?php

declare(strict_types=1);

namespace NeoPHP\Component\Asset\Provider;

use NeoPHP\Component\Asset\AssetManager;
use NeoPHP\Component\Asset\Contract\AssetInterface;
use NeoPHP\Component\Config\Contract\ConfigInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Http\Request\Request;

class AssetProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.asset';

    public function register(ContainerInterface $container): void
    {
        $container->singleton(AssetInterface::class, static function (ContainerInterface $container): AssetInterface {
            $config = $container->has(ConfigInterface::class) ? (array) $container->get(ConfigInterface::class)->get(self::CONFIG_KEY, []) : [];
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

        $container->alias(AssetManager::class, AssetInterface::class);
    }
}