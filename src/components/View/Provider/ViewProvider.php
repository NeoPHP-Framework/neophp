<?php

declare(strict_types=1);

namespace NeoPHP\Component\View\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Kernel\KernelManagerInterface;
use NeoPHP\Component\Kernel\Module\InstalledPackages;
use NeoPHP\Component\Kernel\Module\ModuleSources;
use NeoPHP\Component\View\Contract\ViewHelperInterface;
use NeoPHP\Component\View\Discovery\HelperDiscovery;
use NeoPHP\Component\View\Engine\PhpEngine;
use NeoPHP\Component\View\Engine\TwigEngine;
use NeoPHP\Component\View\Exception\ViewException;
use NeoPHP\Component\View\ViewManager;
use NeoPHP\Component\View\ViewManagerInterface;

/**
 * @internal
 */
class ViewProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.view';

    public const FRAMEWORK_SOURCES = [
        'components' => 'NeoPHP\\Component\\',
        'packages' => 'NeoPHP\\Package\\',
        'process' => 'NeoPHP\\Process\\',
    ];

    public const APPLICATION_NAMESPACE = 'App\\';

    public const PACKAGE_OVERRIDES_DIRECTORY = 'packages';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(ViewManagerInterface::class, static function (ContainerManagerInterface $container): ViewManagerInterface {
            $config = self::config($container);
            $rootPath = $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : (string) getcwd();
            $templatesPath = $container->has('kernel.templates_path') ? (string) $container->get('kernel.templates_path') : $rootPath . DIRECTORY_SEPARATOR . 'templates';
            $debug = $container->has('kernel.debug') && (bool) $container->get('kernel.debug');

            $engines = [new PhpEngine()];

            if (TwigEngine::isAvailable() && ($config['twig']['enabled'] ?? true) !== false) {
                $engines[] = new TwigEngine(array_replace([
                    'cache' => $rootPath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'twig',
                    'debug' => $debug,
                    'auto_reload' => true,
                    'strict_variables' => $debug,
                ], (array) ($config['twig'] ?? [])));
            }

            $view = new ViewManager((array) ($config['paths'] ?? [$templatesPath]), $engines);

            foreach ((array) ($config['namespaces'] ?? []) as $namespace => $path) {
                $view->addPath((string) $path, (string) $namespace);
            }

            $kernel = $container->bound(KernelManagerInterface::class) ? $container->get(KernelManagerInterface::class) : null;

            self::addPackageTemplates($view, $rootPath, $templatesPath, $kernel);

            foreach (self::helpers($rootPath, $config, $debug, $kernel) as $class) {
                $helper = $container->get($class);

                if (!$helper instanceof ViewHelperInterface) {
                    throw new ViewException('The view helper "{class}" must implement {interface}.', 0, null, [
                        'class' => $class,
                        'interface' => ViewHelperInterface::class,
                    ]);
                }

                $view->addExtension($helper);
            }

            return $view;
        });

        $container->alias(ViewManager::class, ViewManagerInterface::class);
    }

    protected static function helpers(string $rootPath, array $config, bool $debug = false, ?KernelManagerInterface $kernel = null): array
    {
        $discovery = new HelperDiscovery([], $debug);
        $frameworkPath = dirname(__DIR__, 3);

        foreach (self::FRAMEWORK_SOURCES as $directory => $namespace) {
            $discovery->addSource($frameworkPath . DIRECTORY_SEPARATOR . $directory, $namespace);
        }

        $discovery->addSource($rootPath . DIRECTORY_SEPARATOR . 'src', self::APPLICATION_NAMESPACE);

        foreach (ModuleSources::external($kernel) as $directory => $namespace) {
            $discovery->addSource((string) $directory, (string) $namespace);
        }

        $helpers = array_values(array_filter($discovery->discover(), static fn (string $class): bool => $kernel?->isEnabled($class) ?? true));

        foreach ((array) ($config['helpers'] ?? []) as $helper) {
            $helpers[] = (string) $helper;
        }

        return array_values(array_unique($helpers));
    }

    protected static function addPackageTemplates(ViewManagerInterface $view, string $rootPath, string $templatesPath, ?KernelManagerInterface $kernel): void
    {
        foreach (InstalledPackages::all($rootPath) as $package) {
            if ($package['templates'] === null || ($kernel !== null && $package['modules'] !== [] && !$kernel->isEnabled($package['modules'][0]))) {
                continue;
            }

            $override = $templatesPath . DIRECTORY_SEPARATOR . self::PACKAGE_OVERRIDES_DIRECTORY . DIRECTORY_SEPARATOR . $package['alias'];

            if (is_dir($override)) {
                $view->addPath($override, $package['alias']);
            }

            $view->addPath($package['templates'], $package['alias']);
        }
    }

    protected static function config(ContainerManagerInterface $container): array
    {
        if (!$container->has(ConfigManagerInterface::class)) {
            return [];
        }

        return (array) $container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []);
    }
}