<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Module;

use NeoPHP\Component\Kernel\Exception\KernelException;

final class InstalledPackages
{
    public const TYPE = 'neophp-package';

    public const COMPOSER_KEY = 'neophp';

    public const FILE = 'vendor/composer/installed.json';

    public const IGNORED = ['neophp/framework'];

    private static array $cache = [];

    public static function all(string $rootPath): array
    {
        $file = self::file($rootPath);
        clearstatcache(true, $file);
        $key = $file . '@' . (is_file($file) ? (int) filemtime($file) . ':' . (int) filesize($file) : '0');

        return self::$cache[$key] ??= self::read($file);
    }

    public static function find(string $rootPath, string $name): ?array
    {
        $packages = self::all($rootPath);

        if (isset($packages[$name])) {
            return $packages[$name];
        }

        foreach ($packages as $package) {
            if ($package['alias'] === $name) {
                return $package;
            }
        }

        return null;
    }

    public static function file(string $rootPath): string
    {
        return rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::FILE);
    }

    public static function isNeoPhpPackage(array $package): bool
    {
        if (in_array($package['name'] ?? null, self::IGNORED, true)) {
            return false;
        }

        return ($package['type'] ?? null) === self::TYPE || !empty($package['extra'][self::COMPOSER_KEY]['modules']);
    }

    public static function alias(string $package, mixed $alias = null): string
    {
        if ($alias !== null && $alias !== '') {
            if (!is_string($alias) || preg_match('/^[a-z][a-z0-9_]*$/', $alias) !== 1) {
                throw new KernelException('The name "{alias}" of the package "{package}" (extra.neophp.name) must be snake_case: lowercase letters, digits and underscores.', 0, null, [
                    'alias' => is_scalar($alias) ? (string) $alias : get_debug_type($alias),
                    'package' => $package,
                ]);
            }

            return $alias;
        }

        $short = str_contains($package, '/') ? substr($package, (int) strrpos($package, '/') + 1) : $package;
        $short = (string) preg_replace('/^(neophp|neo)[-_]/i', '', $short);
        $short = trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $short)), '_');

        if ($short === '') {
            return 'package';
        }

        return ctype_digit($short[0]) ? 'package_' . $short : $short;
    }

    private static function read(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (!is_array($data)) {
            return [];
        }

        $packages = [];

        foreach ((array) ($data['packages'] ?? $data) as $package) {
            if (!is_array($package) || !isset($package['name']) || !self::isNeoPhpPackage($package)) {
                continue;
            }

            $definition = self::define($package, dirname($file));
            $packages[$definition['name']] = $definition;
        }

        ksort($packages);

        return $packages;
    }

    private static function define(array $package, string $composerDirectory): array
    {
        $name = (string) $package['name'];
        $extra = (array) ($package['extra'][self::COMPOSER_KEY] ?? []);
        $path = isset($package['install-path']) ? realpath($composerDirectory . DIRECTORY_SEPARATOR . $package['install-path']) : false;
        $path = $path !== false ? $path : '';

        return [
            'name' => $name,
            'alias' => self::alias($name, $extra['name'] ?? null),
            'version' => (string) ($package['pretty_version'] ?? $package['version'] ?? ''),
            'description' => (string) ($package['description'] ?? ''),
            'type' => (string) ($package['type'] ?? 'library'),
            'path' => $path,
            'modules' => array_values(array_map(static fn (mixed $class): string => ltrim((string) $class, '\\'), (array) ($extra['modules'] ?? []))),
            'config' => self::directory($path, $extra['config'] ?? 'config'),
            'templates' => self::directory($path, $extra['templates'] ?? 'templates'),
        ];
    }

    private static function directory(string $path, mixed $relative): ?string
    {
        if ($path === '' || !is_string($relative) || trim($relative, '/\\') === '') {
            return null;
        }

        $directory = $path . DIRECTORY_SEPARATOR . trim(str_replace('\\', '/', $relative), '/');

        return is_dir($directory) ? $directory : null;
    }
}