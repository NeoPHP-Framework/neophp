<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Module;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Kernel\Cache\ResourceCache;
use NeoPHP\Component\Kernel\KernelManagerInterface;
use ReflectionClass;

final class ModuleSources
{
    public static function external(?KernelManagerInterface $kernel): array
    {
        if ($kernel === null) {
            return [];
        }

        $framework = self::normalize(dirname(__DIR__, 3));
        $application = self::normalize($kernel->getRootPath() . DIRECTORY_SEPARATOR . 'src');
        $sources = [];

        foreach ($kernel->getModules() as $class => $module) {
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $file = $reflection->getFileName();

            if ($file === false) {
                continue;
            }

            $directory = self::normalize(dirname($file));

            if (self::contains($framework, $directory) || self::contains($application, $directory)) {
                continue;
            }

            $namespace = $module['namespace'] !== '' ? $module['namespace'] : $reflection->getNamespaceName();
            $sources[$directory] = rtrim($namespace, '\\') . '\\';
        }

        return $sources;
    }

    public static function paths(ContainerManagerInterface $container): array
    {
        $paths = $container->has('kernel.root_path') ? [(string) $container->get('kernel.root_path') . DIRECTORY_SEPARATOR . 'src'] : [];

        return [...$paths, ...array_keys(self::external(self::kernel($container)))];
    }

    public static function resources(ContainerManagerInterface $container): array
    {
        if (!$container->has('kernel.root_path')) {
            return [];
        }

        $file = InstalledPackages::file((string) $container->get('kernel.root_path'));

        return [$file => is_file($file) ? (int) filemtime($file) : ResourceCache::MISSING];
    }

    public static function kernel(ContainerManagerInterface $container): ?KernelManagerInterface
    {
        return $container->bound(KernelManagerInterface::class) ? $container->get(KernelManagerInterface::class) : null;
    }

    private static function normalize(string $path): string
    {
        $real = realpath($path);

        return rtrim(str_replace('\\', '/', $real !== false ? $real : $path), '/');
    }

    private static function contains(string $root, string $directory): bool
    {
        return $directory === $root || str_starts_with($directory, $root . '/');
    }
}