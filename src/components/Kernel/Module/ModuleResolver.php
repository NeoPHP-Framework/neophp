<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Module;

use NeoPHP\Component\Kernel\Exception\KernelException;

/**
 * @internal
 */
final class ModuleResolver
{
    public const TYPES = ['component', 'package', 'process'];

    public function __construct(private string $environment)
    {
    }

    public function resolve(array $modules, array $config, string $file, array $mandatory = []): array
    {
        $disabled = [];

        foreach ($config as $class => $value) {
            if (!is_string($class) || $class === '') {
                throw new KernelException('The keys of "{file}" must be module classes, "{key}" given.', 0, null, ['file' => $file, 'key' => (string) $class]);
            }

            $class = ltrim($class, '\\');

            if (!isset($modules[$class])) {
                throw new KernelException('"{class}" in "{file}" is not a module: only the classes declared with #[Component], #[Package] or #[Process] can be enabled or disabled.', 0, null, ['class' => $class, 'file' => $file]);
            }

            if (!$this->isEnabled($class, $value, $file)) {
                if (in_array($class, $mandatory, true)) {
                    throw new KernelException('"{class}" cannot be disabled: remove it from "{file}".', 0, null, ['class' => $class, 'file' => $file]);
                }

                $disabled[$class] = true;
            }
        }

        $enabled = array_diff_key($modules, $disabled);

        foreach ($enabled as $class => $module) {
            foreach ($module['requires'] as $required) {
                if (!isset($modules[$required])) {
                    throw new KernelException('The {type} "{class}" requires "{required}", which is not a module.', 0, null, ['type' => $module['type'], 'class' => $class, 'required' => $required]);
                }

                if (!isset($enabled[$required])) {
                    throw new KernelException('The {type} "{class}" requires "{required}", which is disabled in "{file}": enable it or disable "{class}".', 0, null, [
                        'type' => $module['type'],
                        'class' => $class,
                        'required' => $required,
                        'file' => $file,
                    ]);
                }
            }
        }

        return [
            'modules' => $this->sort($enabled),
            'disabled' => array_map(static fn (string $class): string => $modules[$class]['namespace'], array_keys($disabled)),
        ];
    }

    private function isEnabled(string $class, mixed $value, string $file): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (!is_array($value)) {
            throw new KernelException('The value of "{class}" in "{file}" must be a boolean or an array of environment => boolean, {type} given.', 0, null, [
                'class' => $class,
                'file' => $file,
                'type' => get_debug_type($value),
            ]);
        }

        foreach ($value as $environment => $enabled) {
            if (!is_string($environment) || !is_bool($enabled)) {
                throw new KernelException('The environments of "{class}" in "{file}" must be written as \'environment\' => true|false.', 0, null, ['class' => $class, 'file' => $file]);
            }
        }

        return $value[$this->environment] ?? $value['all'] ?? false;
    }

    private function sort(array $modules): array
    {
        uksort($modules, static fn (string $a, string $b): int => [array_search($modules[$a]['type'], self::TYPES, true), $a] <=> [array_search($modules[$b]['type'], self::TYPES, true), $b]);

        $sorted = [];
        $visiting = [];

        $visit = static function (string $class, array $path) use (&$visit, &$sorted, &$visiting, $modules): void {
            if (isset($sorted[$class])) {
                return;
            }

            if (isset($visiting[$class])) {
                throw new KernelException('The modules require each other: {cycle}.', 0, null, ['cycle' => implode(' -> ', [...$path, $class])]);
            }

            $visiting[$class] = true;

            foreach ($modules[$class]['requires'] as $required) {
                $visit($required, [...$path, $class]);
            }

            unset($visiting[$class]);
            $sorted[$class] = $modules[$class];
        };

        foreach (array_keys($modules) as $class) {
            $visit($class, []);
        }

        return $sorted;
    }
}