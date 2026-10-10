<?php

declare(strict_types=1);

namespace NeoPHP\Process\Package\Maker;

use FilesystemIterator;
use NeoPHP\Component\Kernel\Module\InstalledPackages;
use NeoPHP\Process\Package\Exception\PackageException;
use NeoPHP\Process\Package\PackageManager;
use NeoPHP\Process\Package\PackageManagerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * @internal
 */
class PackageMaker
{
    public const STUB_EXTENSION = '.stub';

    public const NAMESPACE_PATTERN = '/^[A-Z][A-Za-z0-9]*(\\\\[A-Z][A-Za-z0-9]*)*$/';

    public const DEFAULT_FRAMEWORK_CONSTRAINT = '^2.1';

    protected string $skeletonDir;

    public function __construct(?string $skeletonDir = null)
    {
        $this->skeletonDir = $skeletonDir ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . 'skeleton';
    }

    public function variables(string $package, ?string $namespace = null, ?string $description = null, ?string $frameworkVersion = null): array
    {
        $package = strtolower(trim($package));

        if (preg_match(PackageManager::NAME_PATTERN, $package) !== 1) {
            throw new PackageException('"{package}" is not a valid Composer package name: vendor/name is expected (e.g. acme/neo-billing).', 0, null, ['package' => $package]);
        }

        [$vendor] = explode('/', $package, 2);
        $alias = InstalledPackages::alias($package);
        $class = static::studly($alias);
        $namespace = $namespace !== null && trim($namespace) !== '' ? trim(str_replace('/', '\\', $namespace), '\\') : static::studly($vendor) . '\\' . $class;

        if (preg_match(self::NAMESPACE_PATTERN, $namespace) !== 1) {
            throw new PackageException('"{namespace}" is not a valid namespace: StudlyCase segments separated by backslashes are expected (e.g. Acme\Billing).', 0, null, ['namespace' => $namespace]);
        }

        return [
            'package' => $package,
            'alias' => $alias,
            'class' => $class,
            'property' => lcfirst($class),
            'namespace' => $namespace,
            'namespace_json' => str_replace('\\', '\\\\', $namespace),
            'description' => $description !== null && trim($description) !== '' ? trim($description) : sprintf('%s package for NeoPHP.', $class),
            'framework' => static::frameworkConstraint($frameworkVersion),
            'directory' => '',
        ];
    }

    public function make(string $directory, array $variables, bool $force = false): array
    {
        $directory = rtrim($directory, '/\\');

        if (is_dir($directory) && !$force && (new FilesystemIterator($directory))->valid()) {
            throw new PackageException('The directory "{directory}" is not empty: choose another --path, or use --force to overwrite its files.', 0, null, ['directory' => $directory]);
        }

        $replacements = [];

        foreach ($variables as $name => $value) {
            $replacements['{{ ' . $name . ' }}'] = (string) $value;
        }

        $report = [];

        foreach ($this->stubs() as $relative => $stub) {
            $relative = strtr($relative, ['__CLASS__' => (string) $variables['class'], '__ALIAS__' => (string) $variables['alias']]);
            $file = $directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $content = strtr((string) file_get_contents($stub), $replacements);

            if (str_ends_with($relative, '.php')) {
                $content = static::sortImports($content);
            }

            $status = is_file($file) ? PackageManagerInterface::STATUS_OVERWRITTEN : PackageManagerInterface::STATUS_CREATED;
            $this->write($file, $content);
            $report[$relative] = $status;
        }

        return $report;
    }

    public function getSkeletonDir(): string
    {
        return $this->skeletonDir;
    }

    public static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(strtolower((string) preg_replace('/[^a-z0-9]+/i', ' ', $value))));
    }

    public static function frameworkConstraint(?string $version): string
    {
        return $version !== null && preg_match('/^v?(\d+)\.(\d+)/', $version, $matches) === 1
            ? '^' . $matches[1] . '.' . $matches[2]
            : self::DEFAULT_FRAMEWORK_CONSTRAINT;
    }

    public static function sortImports(string $content): string
    {
        return (string) preg_replace_callback('/(?:^use [^;\n]+;\n)+/m', static function (array $matches): string {
            $lines = explode("\n", rtrim($matches[0], "\n"));
            usort($lines, static fn (string $a, string $b): int => strcmp(strtolower($a), strtolower($b)));

            return implode("\n", $lines) . "\n";
        }, $content);
    }

    protected function stubs(): array
    {
        if (!is_dir($this->skeletonDir)) {
            throw new PackageException('The package skeleton directory "{directory}" does not exist.', 0, null, ['directory' => $this->skeletonDir]);
        }

        $stubs = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->skeletonDir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || !str_ends_with($file->getFilename(), self::STUB_EXTENSION)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->skeletonDir) + 1));
            $stubs[substr($relative, 0, -strlen(self::STUB_EXTENSION))] = $file->getPathname();
        }

        ksort($stubs);

        return $stubs;
    }

    protected function write(string $file, string $content): void
    {
        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new PackageException('Unable to create the directory "{directory}".', 0, null, ['directory' => $directory]);
        }

        if (file_put_contents($file, $content) === false) {
            throw new PackageException('Unable to write the file "{file}".', 0, null, ['file' => $file]);
        }
    }
}