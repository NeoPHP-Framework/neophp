<?php

declare(strict_types=1);

namespace NeoPHP\Component\Config\Contract;

use NeoPHP\Component\Config\Exception\ConfigException;

abstract class AbstractConfig implements ConfigInterface
{
    public const PLACEHOLDER = '(env\([^)%\s]*\)|[A-Za-z_][\w-]*(?:\.[\w-]+)+)';

    protected array $items = [];

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