<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Provider;

use NeoPHP\Component\Config\Contract\ConfigInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Package\Translation\Contract\TranslatorInterface;
use NeoPHP\Package\Translation\LocaleDetector;
use NeoPHP\Package\Translation\Trace\TranslationTrace;
use NeoPHP\Package\Translation\TranslationManager;
use NeoPHP\Package\Yaml\Contract\YamlInterface;

class TranslationProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'packages.translation';

    public const PROFILER_CONFIG_ID = 'web_profiler.config';

    public function register(ContainerInterface $container): void
    {
        $container->singleton(TranslatorInterface::class, static function (ContainerInterface $container): TranslatorInterface {
            $config = $container->has(ConfigInterface::class) ? (array) ($container->get(ConfigInterface::class)->get(self::CONFIG_KEY, []) ?? []) : [];

            $translator = new TranslationManager(
                $config,
                $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : null,
                $container->has('kernel.cache_path') ? (string) $container->get('kernel.cache_path') : null,
                $container->has('kernel.debug') && (bool) $container->get('kernel.debug'),
                $container->has(YamlInterface::class) ? $container->get(YamlInterface::class) : null,
            );

            return self::profilingEnabled($container) ? $translator->setTrace(new TranslationTrace()) : $translator;
        });

        $container->singleton(LocaleDetector::class, static fn (ContainerInterface $container): LocaleDetector => new LocaleDetector($container->get(TranslatorInterface::class)));
        $container->alias(TranslationManager::class, TranslatorInterface::class);
        $container->alias('translator', TranslatorInterface::class);
    }

    protected static function profilingEnabled(ContainerInterface $container): bool
    {
        if (!$container->bound(self::PROFILER_CONFIG_ID) && !$container->has(self::PROFILER_CONFIG_ID)) {
            return false;
        }

        $config = $container->get(self::PROFILER_CONFIG_ID);

        return is_array($config) && (bool) ($config['enabled'] ?? false);
    }
}