<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\HttpClient\HttpClientManagerInterface;
use NeoPHP\Package\NeoAI\NeoAiManager;
use NeoPHP\Package\NeoAI\NeoAiManagerInterface;

/**
 * @internal
 */
class NeoAiProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'packages.neo_ai';

    public const CONFIG_ID = 'neo_ai.config';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(self::CONFIG_ID, static fn (ContainerManagerInterface $container): array => self::configure($container));

        $container->singleton(NeoAiManagerInterface::class, static fn (ContainerManagerInterface $container): NeoAiManagerInterface => new NeoAiManager(
            $container->get(self::CONFIG_ID),
            $container,
            $container->has(HttpClientManagerInterface::class) ? $container->get(HttpClientManagerInterface::class) : null,
        ));
        $container->alias(NeoAiManager::class, NeoAiManagerInterface::class);
        $container->alias('neo_ai', NeoAiManagerInterface::class);
    }

    public static function configure(ContainerManagerInterface $container): array
    {
        $config = $container->has(ConfigManagerInterface::class) ? (array) ($container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) ?? []) : [];
        $root = $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : (string) getcwd();
        $storage = trim((string) ($config['storage'] ?? ''));

        $config['debug'] = $container->has('kernel.debug') && (bool) $container->get('kernel.debug');
        $config['root'] = $root;
        $config['storage'] = $storage === '' ? $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'ai' : (preg_match('#^([a-zA-Z]:)?[/\\\\]#', $storage) === 1 ? $storage : $root . DIRECTORY_SEPARATOR . ltrim($storage, './\\'));

        return NeoAiManager::normalize($config);
    }
}