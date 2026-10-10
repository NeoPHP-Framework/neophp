<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Util;

use NeoPHP\Component\Kernel\Attribute\AbstractModule;
use NeoPHP\Component\Kernel\KernelManagerInterface;
use NeoPHP\Component\Kernel\Module\InstalledPackages;
use ReflectionAttribute;
use ReflectionClass;
use Throwable;

/**
 * @internal
 */
class ModuleInspector
{
    public const MODULES_FILE = 'config.php';

    public const TYPES = ['component' => 0, 'package' => 1, 'process' => 2];

    public const FEATURES = ['config', 'routes', 'templates', 'translations', 'assets', 'entities', 'migrations'];

    protected ?array $settings = null;

    public function __construct(protected KernelManagerInterface $kernel)
    {
    }

    public function getEnvironment(): string
    {
        return $this->kernel->getEnvironment();
    }

    public function packages(): array
    {
        $rows = [];

        foreach (InstalledPackages::all($this->kernel->getRootPath()) as $package) {
            $setting = $package['modules'] !== [] ? $this->setting($package['modules'][0]) : null;

            $rows[] = [
                'name' => $package['name'],
                'alias' => $package['alias'],
                'version' => $package['version'],
                'description' => $package['description'],
                'modules' => $package['modules'],
                'active' => $package['modules'] === [] ? null : InstalledPackages::isEnabled($package, $this->kernel),
                'environments' => $package['modules'] === [] ? '' : $this->environments($setting),
                'features' => array_values(array_filter(self::FEATURES, static fn (string $feature): bool => ($package[$feature] ?? null) !== null)),
                'published' => is_dir($this->kernel->getConfigPath() . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR . $package['alias']),
            ];
        }

        return $rows;
    }

    public function modules(): array
    {
        $enabled = $this->kernel->getModules();
        $sources = [];

        foreach (InstalledPackages::all($this->kernel->getRootPath()) as $package) {
            foreach ($package['modules'] as $module) {
                $sources[$module] = $package['name'];
            }
        }

        $rows = [];

        foreach (array_unique([...array_keys($enabled), ...array_keys($this->settings())]) as $class) {
            $class = (string) $class;
            $rows[] = [
                'class' => $class,
                'name' => substr($class, (int) strrpos($class, '\\') + 1),
                'type' => (string) ($enabled[$class]['type'] ?? $this->type($class)),
                'source' => preg_match('/^NeoPHP\\\\(Component|Package|Process)\\\\/', $class) === 1 ? 'framework' : ($sources[$class] ?? 'application'),
                'active' => isset($enabled[$class]),
                'environments' => $this->environments($this->setting($class)),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [self::TYPES[$a['type']] ?? 3, $a['class']] <=> [self::TYPES[$b['type']] ?? 3, $b['class']]);

        return $rows;
    }

    public function environments(mixed $setting): string
    {
        if ($setting === null || $setting === true) {
            return 'all';
        }

        if (!is_array($setting)) {
            return 'none';
        }

        $enabled = [];
        $disabled = [];

        foreach ($setting as $environment => $value) {
            if ($environment === 'all') {
                continue;
            }

            if ($value) {
                $enabled[] = (string) $environment;
            } else {
                $disabled[] = (string) $environment;
            }
        }

        if ((bool) ($setting['all'] ?? false)) {
            return $disabled === [] ? 'all' : 'all except ' . implode(', ', $disabled);
        }

        return $enabled === [] ? 'none' : implode(', ', $enabled);
    }

    protected function setting(string $class): mixed
    {
        return $this->settings()[ltrim($class, '\\')] ?? null;
    }

    protected function settings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $file = $this->kernel->getConfigPath() . DIRECTORY_SEPARATOR . self::MODULES_FILE;
        $settings = [];

        if (is_file($file)) {
            try {
                $values = (static fn (string $__file): mixed => require $__file)($file);
            } catch (Throwable) {
                $values = [];
            }

            foreach (is_array($values) ? $values : [] as $class => $value) {
                $settings[ltrim((string) $class, '\\')] = $value;
            }
        }

        return $this->settings = $settings;
    }

    protected function type(string $class): string
    {
        if (!class_exists($class)) {
            return 'unknown';
        }

        $attributes = (new ReflectionClass($class))->getAttributes(AbstractModule::class, ReflectionAttribute::IS_INSTANCEOF);

        return $attributes === [] ? 'unknown' : $attributes[0]->newInstance()->getType();
    }
}