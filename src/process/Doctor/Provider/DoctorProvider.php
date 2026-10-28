<?php

declare(strict_types=1);

namespace NeoPHP\Process\Doctor\Provider;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Kernel\Exception\KernelException;
use NeoPHP\Component\Kernel\Module\ModuleSources;
use NeoPHP\Process\Doctor\Contract\CheckInterface;
use NeoPHP\Process\Doctor\Discovery\CheckDiscovery;
use NeoPHP\Process\Doctor\DoctorManager;
use NeoPHP\Process\Doctor\DoctorManagerInterface;

/**
 * @internal
 */
class DoctorProvider extends AbstractProvider
{
    public const FRAMEWORK_SOURCES = ['components', 'packages', 'process'];

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(DoctorManagerInterface::class, static fn (ContainerManagerInterface $container): DoctorManagerInterface => new DoctorManager($container, self::discover($container)));
        $container->alias(DoctorManager::class, DoctorManagerInterface::class);
        $container->alias('doctor', DoctorManagerInterface::class);
    }

    /**
     * Finds the checks of the enabled modules and of the application (neo doctor runs rarely: the result is not cached).
     *
     * @return list<class-string<CheckInterface>>
     * @throws KernelException
     */
    public static function discover(ContainerManagerInterface $container): array
    {
        $frameworkPath = dirname(__DIR__, 3);
        $sources = array_map(static fn (string $directory): string => $frameworkPath . DIRECTORY_SEPARATOR . $directory, self::FRAMEWORK_SOURCES);
        $checks = (new CheckDiscovery($sources, ModuleSources::paths($container)))->discover();
        $kernel = ModuleSources::kernel($container);

        return $kernel === null ? $checks : array_values(array_filter($checks, static fn (string $class): bool => $kernel->isEnabled($class)));
    }
}