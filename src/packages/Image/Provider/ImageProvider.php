<?php

declare(strict_types=1);

namespace NeoPHP\Package\Image\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Upload\UploadManagerInterface;
use NeoPHP\Package\Image\ImageManager;
use NeoPHP\Package\Image\ImageManagerInterface;

/**
 * @internal
 */
class ImageProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'packages.image';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(ImageManagerInterface::class, static function (ContainerManagerInterface $container): ImageManagerInterface {
            $config = $container->has(ConfigManagerInterface::class) ? (array) ($container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) ?? []) : [];
            $rootPath = $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : (string) getcwd();
            $publicPath = $container->has('kernel.public_path') ? (string) $container->get('kernel.public_path') : $rootPath . '/public';

            return new ImageManager(
                $container->get(UploadManagerInterface::class),
                $config,
                $publicPath . '/media/cache',
                static fn (): string => $container->has(Request::class) ? $container->get(Request::class)->getBasePath() : '',
            );
        });

        $container->alias(ImageManager::class, ImageManagerInterface::class);
        $container->alias('image', ImageManagerInterface::class);
    }
}