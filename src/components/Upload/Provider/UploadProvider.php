<?php

declare(strict_types=1);

namespace NeoPHP\Component\Upload\Provider;

use NeoPHP\Component\Config\Contract\ConfigInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Upload\Contract\UploaderInterface;
use NeoPHP\Component\Upload\UploadManager;

class UploadProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.upload';

    public function register(ContainerInterface $container): void
    {
        $container->singleton(UploaderInterface::class, static function (ContainerInterface $container): UploaderInterface {
            $config = $container->has(ConfigInterface::class) ? (array) $container->get(ConfigInterface::class)->get(self::CONFIG_KEY, []) : [];
            $rootPath = $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : (string) getcwd();
            $publicPath = $container->has('kernel.public_path') ? (string) $container->get('kernel.public_path') : $rootPath . '/public';

            return UploadManager::fromConfig($config, [
                'path' => $publicPath . '/uploads',
                'public_url' => '/uploads',
            ], static fn (): string => $container->has(Request::class) ? $container->get(Request::class)->getBasePath() : '');
        });

        $container->alias(UploadManager::class, UploaderInterface::class);
        $container->alias('uploader', UploaderInterface::class);
    }
}