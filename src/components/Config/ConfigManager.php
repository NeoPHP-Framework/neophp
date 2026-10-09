<?php

declare(strict_types=1);

namespace NeoPHP\Component\Config;

use NeoPHP\Component\Config\Exception\ConfigException;
use NeoPHP\Component\Config\Provider\ConfigProvider;
use NeoPHP\Component\Kernel\Attribute\Component;
use NeoPHP\Package\Yaml\YamlManager;
use NeoPHP\Package\Yaml\YamlManagerInterface;

#[Component(provider: ConfigProvider::class, requires: [YamlManager::class])]
final class ConfigManager implements ConfigManagerInterface
{
    public const PLACEHOLDER = '(env\([^)%\s]*\)|[A-Za-z_][\w-]*(?:\.[\w-]+)+)';

    protected array $items = [];

    public function __construct(
        protected YamlManagerInterface $yaml,
        array $parameters = [],
    ) {
        foreach ($parameters as $key => $value) {
            $this->set((string) $key, $value);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $this->items;
        }

        $current = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    public function has(string $key): bool
    {
        $marker = new \stdClass();

        return $this->get($key, $marker) !== $marker;
    }

    public function set(string $key, mixed $value): void
    {
        $current = &$this->items;

        foreach (explode('.', $key) as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }

        $current = $value;
    }

    public function all(): array
    {
        return $this->items;
    }

    public function resolve(mixed $value): mixed
    {
        return $this->resolveValue($value, []);
    }

    public function loadDirectory(string $directory, array $exclude = []): static
    {
        $directory = rtrim(str_replace('\\', '/', $directory), '/');

        if (!is_dir($directory)) {
            return $this;
        }

        $exclude = array_map(static fn (string $path): string => trim(str_replace('\\', '/', $path), '/'), $exclude);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['yaml', 'yml'], true)) {
                continue;
            }

            $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($directory)), '/');

            foreach ($exclude as $excluded) {
                if ($relative === $excluded || str_starts_with($relative, $excluded . '/')) {
                    continue 2;
                }
            }

            $files[$relative] = $file->getPathname();
        }

        ksort($files);

        foreach ($files as $relative => $path) {
            $key = str_replace('/', '.', (string) preg_replace('/\.ya?ml$/i', '', $relative));
            $this->loadFile($path, $key, false);
        }

        $this->items = $this->resolve($this->items);

        return $this;
    }

    public function loadFile(string $file, string $key = '', bool $resolve = true): static
    {
        $data = $this->yaml->parseFile($file) ?? [];

        if (!is_array($data)) {
            throw new ConfigException('The configuration file "{file}" must contain a mapping.', 0, null, ['file' => $file]);
        }

        if ($resolve) {
            $data = $this->resolve($data);
        }

        if ($key === '') {
            $this->items = $this->merge($this->items, $data);

            return $this;
        }

        $existing = $this->get($key);
        $this->set($key, is_array($existing) ? $this->merge($existing, $data) : $data);

        return $this;
    }

    protected function resolveValue(mixed $value, array $resolving): mixed
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->resolveValue($v, $resolving);
            }

            return $value;
        }

        if (!is_string($value) || !str_contains($value, '%')) {
            return $value;
        }

        if (preg_match('/^%' . static::PLACEHOLDER . '%$/', $value, $m) === 1) {
            return $this->resolvePlaceholder($m[1], $resolving);
        }

        $resolved = preg_replace_callback('/%%|%' . static::PLACEHOLDER . '%/', function (array $m) use ($resolving): string {
            if ($m[0] === '%%') {
                return '%';
            }

            $replacement = $this->resolvePlaceholder($m[1], $resolving);

            if (!is_scalar($replacement) && $replacement !== null) {
                throw new ConfigException(sprintf('Placeholder "%%%s%%" cannot be embedded in a string: it does not resolve to a scalar.', $m[1]));
            }

            return is_bool($replacement) ? ($replacement ? 'true' : 'false') : (string) $replacement;
        }, $value);

        return $resolved;
    }

    protected function resolvePlaceholder(string $placeholder, array $resolving): mixed
    {
        if (preg_match('/^env\((?:(\w+):)?([A-Za-z_][A-Za-z0-9_]*)\)$/', $placeholder, $m) === 1) {
            return $this->castEnv($m[1], $m[2], $this->readEnv($m[2]));
        }

        if (isset($resolving[$placeholder])) {
            throw new ConfigException(sprintf('Circular reference detected for placeholder "%%%s%%".', $placeholder));
        }

        if (!$this->has($placeholder)) {
            throw new ConfigException(sprintf('Unknown configuration key "%s" used as a placeholder.', $placeholder));
        }

        return $this->resolveValue($this->get($placeholder), $resolving + [$placeholder => true]);
    }

    protected function readEnv(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return $value === false ? null : (string) $value;
    }

    protected function castEnv(string $type, string $name, ?string $value): mixed
    {
        if ($value === null) {
            throw new ConfigException(sprintf('Environment variable "%s" is not defined.', $name));
        }

        return match ($type) {
            '', 'string' => $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $value,
            'float' => (float) $value,
            'json' => json_decode($value, true, 512, JSON_THROW_ON_ERROR),
            'csv' => $value === '' ? [] : array_map('trim', explode(',', $value)),
            default => throw new ConfigException(sprintf('Unknown env processor "%s" for "%s".', $type, $name)),
        };
    }

    protected function merge(array $base, array $override): array
    {
        if (array_is_list($override) && $override !== []) {
            return $override;
        }

        foreach ($override as $key => $value) {
            $base[$key] = is_array($value) && isset($base[$key]) && is_array($base[$key])
                ? $this->merge($base[$key], $value)
                : $value;
        }

        return $base;
    }
}