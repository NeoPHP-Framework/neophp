<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\Discovery;

use FilesystemIterator;
use NeoPHP\Component\Kernel\Discovery\ClassFinder;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\CommandInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * @internal
 */
class CommandDiscovery
{
    public const DIRECTORY = 'Helper/Console';

    protected array $sources = [];

    protected array $applications = [];

    public function __construct(array $sources = [])
    {
        foreach ($sources as $path => $namespace) {
            $this->addSource((string) $path, (string) $namespace);
        }
    }

    public function addSource(string $path, string $namespace): static
    {
        $this->sources[rtrim(str_replace('\\', '/', $path), '/')] = rtrim($namespace, '\\') . '\\';

        return $this;
    }

    public function addApplicationSource(string $path): static
    {
        $this->applications[] = rtrim(str_replace('\\', '/', $path), '/');

        return $this;
    }

    public function getSources(): array
    {
        return $this->sources;
    }

    public function discover(): array
    {
        $classes = [];

        foreach ($this->sources as $path => $namespace) {
            array_push($classes, ...$this->scan($path, $namespace));
        }

        $finder = new ClassFinder();

        foreach ($this->applications as $path) {
            if (!is_dir($path)) {
                continue;
            }

            foreach ($finder->find($path, 'AsCommand') as $class) {
                if ($this->isCommand($class)) {
                    $classes[] = $class;
                }
            }
        }

        $classes = array_values(array_unique($classes));
        sort($classes);

        return $classes;
    }

    protected function scan(string $path, string $namespace): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $classes = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($path)), '/');

            if (!str_contains('/' . dirname($relative) . '/', '/' . static::DIRECTORY . '/')) {
                continue;
            }

            $class = $namespace . str_replace('/', '\\', substr($relative, 0, -4));

            if ($this->isCommand($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    protected function isCommand(string $class): bool
    {
        if (!class_exists($class) || !is_subclass_of($class, CommandInterface::class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        return $reflection->isInstantiable() && $reflection->getAttributes(AsCommand::class) !== [];
    }
}