<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\Provider;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Kernel\KernelManagerInterface;
use NeoPHP\Process\Console\Command\HelpCommand;
use NeoPHP\Process\Console\Command\InstallCommand;
use NeoPHP\Process\Console\Command\ListCommand;
use NeoPHP\Process\Console\Command\MakeCommandCommand;
use NeoPHP\Process\Console\Command\RouteListCommand;
use NeoPHP\Process\Console\Command\ServeCommand;
use NeoPHP\Process\Console\ConsoleManager;
use NeoPHP\Process\Console\ConsoleManagerInterface;
use NeoPHP\Process\Console\Discovery\CommandDiscovery;

/**
 * @internal
 */
class ConsoleProvider extends AbstractProvider
{
    public const COMMANDS = [
        HelpCommand::class,
        InstallCommand::class,
        ListCommand::class,
        MakeCommandCommand::class,
        RouteListCommand::class,
        ServeCommand::class,
    ];

    public const FRAMEWORK_SOURCES = [
        'components' => 'NeoPHP\\Component\\',
        'packages' => 'NeoPHP\\Package\\',
        'process' => 'NeoPHP\\Process\\',
    ];

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(ConsoleManagerInterface::class, static function (ContainerManagerInterface $container): ConsoleManagerInterface {
            $console = new ConsoleManager($container, $container->has('kernel.version') ? (string) $container->get('kernel.version') : '');
            $rootPath = $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : (string) getcwd();

            $kernel = $container->bound(KernelManagerInterface::class) ? $container->get(KernelManagerInterface::class) : null;

            foreach (self::commands($rootPath, $kernel) as $command) {
                $console->add($command);
            }

            return $console;
        });

        $container->alias(ConsoleManager::class, ConsoleManagerInterface::class);
    }

    protected static function commands(string $rootPath, ?KernelManagerInterface $kernel = null): array
    {
        $discovery = new CommandDiscovery();
        $frameworkPath = dirname(__DIR__, 3);

        foreach (self::FRAMEWORK_SOURCES as $directory => $namespace) {
            $discovery->addSource($frameworkPath . DIRECTORY_SEPARATOR . $directory, $namespace);
        }

        $discovery->addApplicationSource($rootPath . DIRECTORY_SEPARATOR . 'src');

        $discovered = array_filter($discovery->discover(), static fn (string $class): bool => $kernel?->isEnabled($class) ?? true);

        return array_values(array_unique([...self::COMMANDS, ...$discovered]));
    }
}