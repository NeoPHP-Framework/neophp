<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Package\Translation\Locale\LocaleDetector;
use NeoPHP\Package\Translation\Trace\TranslationTrace;
use NeoPHP\Package\Translation\TranslationManager;
use NeoPHP\Package\Translation\TranslationManagerInterface;
use NeoPHP\Package\Yaml\YamlManagerInterface;

/**
 * @internal
 */
class TranslationProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'packages.translation';

    public const PROFILER_CONFIG_ID = 'web_profiler.config';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(TranslationManagerInterface::class, static function (ContainerManagerInterface $container): TranslationManagerInterface {
            $config = $container->has(ConfigManagerInterface::class) ? (array) ($container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) ?? []) : [];

            $translator = new TranslationManager(
                $config,
                $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : null,
                $container->has('kernel.cache_path') ? (string) $container->get('kernel.cache_path') : null,
                $container->has('kernel.debug') && (bool) $container->get('kernel.debug'),
                $container->has(YamlManagerInterface::class) ? $container->get(YamlManagerInterface::class) : null,
            );

            return self::profilingEnabled($container) ? $translator->setTrace(new TranslationTrace()) : $translator;
        });

        $container->singleton(LocaleDetector::class, static fn (ContainerManagerInterface $container): LocaleDetector => new LocaleDetector($container->get(TranslationManagerInterface::class)));
        $container->alias(TranslationManager::class, TranslationManagerInterface::class);
        $container->alias('translator', TranslationManagerInterface::class);
    }

    protected static function profilingEnabled(ContainerManagerInterface $container): bool
    {
        if (!$container->bound(self::PROFILER_CONFIG_ID) && !$container->has(self::PROFILER_CONFIG_ID)) {
            return false;
        }

        $config = $container->get(self::PROFILER_CONFIG_ID);

        return is_array($config) && (bool) ($config['enabled'] ?? false);
    }
}