<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Module;

use NeoPHP\Component\Container\Contract\ProviderInterface;
use NeoPHP\Component\Kernel\Attribute\AbstractModule;
use NeoPHP\Component\Kernel\Discovery\ClassFinder;
use NeoPHP\Component\Kernel\Exception\KernelException;
use ReflectionAttribute;
use ReflectionClass;

/**
 * @internal
 */
final class ModuleDiscovery
{
    public const LAYERS = ['components', 'packages', 'process'];

    public const ATTRIBUTES = ['#[Component', '#[Package', '#[Process'];

    public const COMPOSER_KEY = 'neophp';

    private ClassFinder $finder;

    private array $resources = [];

    public function __construct(
        private string $frameworkPath,
        private string $rootPath,
    ) {
        $this->finder = new ClassFinder();
    }

    public function discover(): array
    {
        $modules = [];

        foreach (self::LAYERS as $layer) {
            $directory = $this->frameworkPath . DIRECTORY_SEPARATOR . $layer;
            $this->track($directory);

            foreach (glob($directory . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $feature) {
                $this->track($feature);
                $found = [];

                foreach (glob($feature . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
                    $this->track($file);

                    if (!$this->declaresModule((string) file_get_contents($file))) {
                        continue;
                    }

                    foreach ($this->finder->find($file) as $class) {
                        if (class_exists($class) && $this->attribute(new ReflectionClass($class)) !== null) {
                            $found[] = $class;
                        }
                    }
                }

                if (count($found) > 1) {
                    throw new KernelException('The directory "{directory}" declares several modules ({classes}): a module has a single entry point.', 0, null, [
                        'directory' => $feature,
                        'classes' => implode(', ', $found),
                    ]);
                }

                foreach ($found as $class) {
                    $modules[$class] = $this->define($class);
                }
            }
        }

        foreach ($this->composerModules() as $class => $package) {
            if (!class_exists($class)) {
                throw new KernelException('The module "{class}" declared by "{package}" does not exist.', 0, null, ['class' => $class, 'package' => $package]);
            }

            $modules[$class] = $this->define($class);
        }

        return $modules;
    }

    public function getResources(): array
    {
        return $this->resources;
    }

    private function define(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $attribute = $this->attribute($reflection);

        if ($attribute === null) {
            throw new KernelException('"{class}" is not a module: declare it with #[Component], #[Package] or #[Process].', 0, null, ['class' => $class]);
        }

        if (!$reflection->isFinal()) {
            throw new KernelException('The module "{class}" must be a final class.', 0, null, ['class' => $class]);
        }

        if (!is_subclass_of($attribute->provider, ProviderInterface::class)) {
            throw new KernelException('The provider "{provider}" of the module "{class}" must implement {interface}.', 0, null, [
                'provider' => $attribute->provider,
                'class' => $class,
                'interface' => ProviderInterface::class,
            ]);
        }

        return [
            'type' => $attribute->getType(),
            'provider' => $attribute->provider,
            'requires' => array_values(array_map(static fn (mixed $required): string => ltrim((string) $required, '\\'), $attribute->requires)),
            'namespace' => $reflection->getNamespaceName(),
        ];
    }

    private function attribute(ReflectionClass $reflection): ?AbstractModule
    {
        $attributes = $reflection->getAttributes(AbstractModule::class, ReflectionAttribute::IS_INSTANCEOF);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    private function declaresModule(string $content): bool
    {
        foreach (self::ATTRIBUTES as $attribute) {
            if (str_contains($content, $attribute)) {
                return true;
            }
        }

        return false;
    }

    private function composerModules(): array
    {
        $modules = [];
        $installed = $this->rootPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.json';
        $root = $this->rootPath . DIRECTORY_SEPARATOR . 'composer.json';

        foreach ([$installed, $root] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $this->track($file);
            $data = json_decode((string) file_get_contents($file), true);

            if (!is_array($data)) {
                continue;
            }

            $packages = $file === $root ? [$data] : (array) ($data['packages'] ?? $data);

            foreach ($packages as $package) {
                if (!is_array($package)) {
                    continue;
                }

                foreach ((array) ($package['extra'][self::COMPOSER_KEY]['modules'] ?? []) as $class) {
                    $modules[ltrim((string) $class, '\\')] = (string) ($package['name'] ?? $file);
                }
            }
        }

        return $modules;
    }

    private function track(string $path): void
    {
        if (file_exists($path)) {
            $this->resources[$path] = (int) filemtime($path);
        }
    }
}