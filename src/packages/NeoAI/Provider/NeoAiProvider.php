<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Provider;

use NeoPHP\Component\Config\Contract\ConfigInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\HttpClient\Contract\HttpClientInterface;
use NeoPHP\Package\NeoAI\NeoAiManager;

class NeoAiProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'packages.neo_ai';

    public const CONFIG_ID = 'neo_ai.config';

    public function register(ContainerInterface $container): void
    {
        $container->singleton(self::CONFIG_ID, static fn (ContainerInterface $container): array => self::configure($container));

        $container->singleton(NeoAiManager::class, static fn (ContainerInterface $container): NeoAiManager => new NeoAiManager(
            $container->get(self::CONFIG_ID),
            $container,
            $container->has(HttpClientInterface::class) ? $container->get(HttpClientInterface::class) : null,
        ));
        $container->alias('neo_ai', NeoAiManager::class);
    }

    public static function configure(ContainerInterface $container): array
    {
        $config = $container->has(ConfigInterface::class) ? (array) ($container->get(ConfigInterface::class)->get(self::CONFIG_KEY, []) ?? []) : [];
        $root = $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : (string) getcwd();
        $storage = trim((string) ($config['storage'] ?? ''));

        $config['debug'] = $container->has('kernel.debug') && (bool) $container->get('kernel.debug');
        $config['root'] = $root;
        $config['storage'] = $storage === '' ? $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'ai' : (preg_match('#^([a-zA-Z]:)?[/\\\\]#', $storage) === 1 ? $storage : $root . DIRECTORY_SEPARATOR . ltrim($storage, './\\'));

        return NeoAiManager::normalize($config);
    }
}