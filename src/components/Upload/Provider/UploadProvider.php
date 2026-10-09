<?php

declare(strict_types=1);

namespace NeoPHP\Component\Upload\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Upload\UploadManager;
use NeoPHP\Component\Upload\UploadManagerInterface;

/**
 * @internal
 */
class UploadProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.upload';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(UploadManagerInterface::class, static function (ContainerManagerInterface $container): UploadManagerInterface {
            $config = $container->has(ConfigManagerInterface::class) ? (array) $container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) : [];
            $rootPath = $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : (string) getcwd();
            $publicPath = $container->has('kernel.public_path') ? (string) $container->get('kernel.public_path') : $rootPath . '/public';

            return UploadManager::fromConfig($config, [
                'path' => $publicPath . '/uploads',
                'public_url' => '/uploads',
            ], static fn (): string => $container->has(Request::class) ? $container->get(Request::class)->getBasePath() : '');
        });

        $container->alias(UploadManager::class, UploadManagerInterface::class);
        $container->alias('uploader', UploadManagerInterface::class);
    }
}