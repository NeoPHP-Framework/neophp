<?php

declare(strict_types=1);

namespace NeoPHP\Component\Routing\Route;

use NeoPHP\Component\Routing\Exception\RouteNotDefinedException;
use NeoPHP\Component\Routing\Exception\RoutingException;

class Route
{
    private const DEFAULT_REQUIREMENT = '[^/]+';

    private string $path;

    private array $methods;

    private ?string $regex = null;

    private array $variables = [];

    private string $source = '';

    public function __construct(
        private string $name,
        string $path,
        private mixed $controller,
        array $methods = [],
        private array $requirements = [],
        private array $defaults = [],
        private array $options = [],
    ) {
        $this->path = self::normalizePath($path);
        $this->methods = array_values(array_unique(array_map('strtoupper', $methods)));
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return (string) preg_replace('#/{2,}#', '/', $path);
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getController(): mixed
    {
        return $this->controller;
    }

    public function getMethods(): array
    {
        return $this->methods;
    }

    public function getRequirements(): array
    {
        return $this->requirements;
    }

    public function getDefaults(): array
    {
        return $this->defaults;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getOption(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public function allowsMethod(string $method): bool
    {
        $method = strtoupper($method);

        return $this->methods === []
            || in_array($method, $this->methods, true)
            || ($method === 'HEAD' && in_array('GET', $this->methods, true));
    }

    public function getVariables(): array
    {
        $this->compile();

        return $this->variables;
    }

    public function getRegex(): string
    {
        $this->compile();

        return (string) $this->regex;
    }

    public function match(string $path): ?array
    {
        $candidate = $path === '/' ? '' : rtrim($path, '/');

        if (preg_match($this->getRegex(), $candidate, $matches) !== 1) {
            return null;
        }

        $parameters = $this->defaults;

        foreach ($this->variables as $variable) {
            if (isset($matches[$variable]) && $matches[$variable] !== '') {
                $parameters[$variable] = rawurldecode($matches[$variable]);
            }
        }

        return $parameters;
    }

    public function generate(array $parameters = []): string
    {
        $this->compile();
        $segments = explode('/', ltrim($this->path, '/'));
        $variables = [];

        $optional = true;
        for ($i = count($segments) - 1; $i >= 0; $i--) {
            $segment = $segments[$i];
            $name = $this->singleVariableIn($segment);

            if ($optional && $name !== null && array_key_exists($name, $this->defaults)
                && (!array_key_exists($name, $parameters) || (string) $parameters[$name] === (string) $this->defaults[$name])) {
                unset($segments[$i]);
                $variables[$name] = true;
                continue;
            }

            $optional = false;
        }

        $path = '';

        foreach ($segments as $segment) {
            $path .= '/' . preg_replace_callback('/\{(\w+)\}/', function (array $m) use ($parameters, &$variables): string {
                    $name = $m[1];
                    $variables[$name] = true;

                    if (array_key_exists($name, $parameters)) {
                        $value = $parameters[$name];
                    } elseif (array_key_exists($name, $this->defaults)) {
                        $value = $this->defaults[$name];
                    } else {
                        throw new RouteNotDefinedException(sprintf('Missing parameter "%s" to generate the URL of route "%s".', $name, $this->name));
                    }

                    if (is_bool($value)) {
                        $value = (int) $value;
                    }

                    if (!is_scalar($value) && !$value instanceof \Stringable) {
                        throw new RouteNotDefinedException(sprintf('Parameter "%s" of route "%s" must be a scalar.', $name, $this->name));
                    }

                    $value = (string) $value;
                    $requirement = $this->requirements[$name] ?? self::DEFAULT_REQUIREMENT;

                    if (preg_match('#^(?:' . $requirement . ')$#u', $value) !== 1) {
                        throw new RouteNotDefinedException(sprintf('Parameter "%s" of route "%s" must match "%s" ("%s" given).', $name, $this->name, $requirement, $value));
                    }

                    return str_replace('%2F', '/', rawurlencode($value));
                }, $segment);
        }

        $query = array_diff_key($parameters, $variables);
        $path = $path === '' ? '/' : $path;

        return $query === [] ? $path : $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function compile(): void
    {
        if ($this->regex !== null) {
            return;
        }

        $segments = $this->path === '/' ? [] : explode('/', ltrim($this->path, '/'));
        $optionalFrom = count($segments);

        for ($i = count($segments) - 1; $i >= 0; $i--) {
            $name = $this->singleVariableIn($segments[$i]);

            if ($name === null || !array_key_exists($name, $this->defaults)) {
                break;
            }

            $optionalFrom = $i;
        }

        $regex = '';
        $variables = [];
        $closing = '';

        foreach ($segments as $index => $segment) {
            $part = '/' . $this->compileSegment($segment, $variables);

            if ($index >= $optionalFrom) {
                $regex .= '(?:' . $part;
                $closing .= ')?';
            } else {
                $regex .= $part;
            }
        }

        $this->variables = $variables;
        $this->regex = '#^' . $regex . $closing . '$#u';

        if (@preg_match($this->regex, '') === false) {
            throw new RoutingException(sprintf('The route "%s" has an invalid requirement (compiled regex: %s).', $this->name, $this->regex));
        }
    }

    private function compileSegment(string $segment, array &$variables): string
    {
        $parts = preg_split('/(\{\w+\})/', $segment, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $regex = '';

        foreach ($parts as $part) {
            if (preg_match('/^\{(\w+)\}$/', $part, $m) === 1) {
                $name = $m[1];

                if (in_array($name, $variables, true)) {
                    throw new RoutingException(sprintf('The route "%s" uses the placeholder "{%s}" more than once.', $this->name, $name));
                }

                $variables[] = $name;
                $regex .= '(?P<' . $name . '>' . ($this->requirements[$name] ?? self::DEFAULT_REQUIREMENT) . ')';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }

        return $regex;
    }

    private function singleVariableIn(string $segment): ?string
    {
        return preg_match('/^\{(\w+)\}$/', $segment, $m) === 1 ? $m[1] : null;
    }
}