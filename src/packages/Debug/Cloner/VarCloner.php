<?php

declare(strict_types=1);

namespace NeoPHP\Package\Debug\Cloner;

use BackedEnum;
use Closure;
use DateTimeInterface;
use ReflectionFunction;
use ReflectionNamedType;
use Throwable;
use UnitEnum;

class VarCloner
{
    protected array $seen = [];

    public function __construct(protected int $maxDepth = 10, protected int $maxItems = 250, protected int $maxString = 1000)
    {
    }

    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    public function getMaxItems(): int
    {
        return $this->maxItems;
    }

    public function getMaxString(): int
    {
        return $this->maxString;
    }

    public function cloneVar(mixed $value): array
    {
        $this->seen = [];

        try {
            return $this->node($value, 0);
        } finally {
            $this->seen = [];
        }
    }

    protected function node(mixed $value, int $depth): array
    {
        return match (true) {
            $value === null => ['type' => 'null'],
            is_bool($value) => ['type' => 'bool', 'value' => $value],
            is_int($value) => ['type' => 'int', 'value' => $value],
            is_float($value) => ['type' => 'float', 'value' => $value],
            is_string($value) => $this->stringNode($value),
            is_array($value) => $this->arrayNode($value, $depth),
            $value instanceof UnitEnum => $this->enumNode($value),
            is_object($value) => $this->objectNode($value, $depth),
            default => $this->resourceNode($value),
        };
    }

    protected function stringNode(string $value): array
    {
        $binary = preg_match('//u', $value) !== 1;
        $length = $binary ? strlen($value) : (int) preg_match_all('/./us', $value);
        $cut = 0;

        if ($this->maxString >= 0 && $length > $this->maxString) {
            $cut = $length - $this->maxString;
            $value = $binary ? substr($value, 0, $this->maxString) : (string) preg_replace('/^(.{' . $this->maxString . '}).*$/us', '$1', $value);
        }

        return ['type' => 'string', 'value' => $value, 'length' => $length, 'binary' => $binary, 'cut' => $cut];
    }

    protected function arrayNode(array $value, int $depth): array
    {
        $node = ['type' => 'array', 'count' => count($value), 'children' => null, 'cut' => 0];

        if ($value === []) {
            $node['children'] = [];

            return $node;
        }

        if ($depth >= $this->maxDepth) {
            return $node;
        }

        $node['children'] = [];

        foreach ($value as $key => $item) {
            if ($this->maxItems >= 0 && count($node['children']) >= $this->maxItems) {
                $node['cut'] = count($value) - count($node['children']);
                break;
            }

            $node['children'][] = ['key' => $key, 'value' => $this->node($item, $depth + 1)];
        }

        return $node;
    }

    protected function enumNode(UnitEnum $value): array
    {
        return [
            'type' => 'enum',
            'class' => $value::class,
            'name' => $value->name,
            'value' => $value instanceof BackedEnum ? $this->node($value->value, 0) : null,
        ];
    }

    protected function objectNode(object $value, int $depth): array
    {
        $id = spl_object_id($value);
        $node = ['type' => 'object', 'class' => $value::class, 'id' => $id, 'children' => null, 'cut' => 0, 'seen' => false];

        if (isset($this->seen[$id])) {
            $node['seen'] = true;

            return $node;
        }

        $this->seen[$id] = true;

        if ($depth >= $this->maxDepth) {
            return $node;
        }

        $node['children'] = [];

        foreach ($this->properties($value) as [$name, $visibility, $item, $declaring]) {
            if ($this->maxItems >= 0 && count($node['children']) >= $this->maxItems) {
                $node['cut']++;
                continue;
            }

            $node['children'][] = ['key' => $name, 'visibility' => $visibility, 'declaring' => $declaring, 'value' => $this->node($item, $depth + 1)];
        }

        return $node;
    }

    protected function properties(object $value): array
    {
        if ($value instanceof Closure) {
            return $this->closureProperties($value);
        }

        $properties = [];

        if ($value instanceof DateTimeInterface) {
            $properties[] = ['date', 'virtual', $value->format('Y-m-d H:i:s.u P'), null];
            $properties[] = ['timezone', 'virtual', $value->getTimezone()->getName(), null];

            return $properties;
        }

        if ($value instanceof Throwable) {
            $properties[] = ['message', 'protected', $value->getMessage(), null];
            $properties[] = ['code', 'protected', $value->getCode(), null];
            $properties[] = ['file', 'protected', $value->getFile(), null];
            $properties[] = ['line', 'protected', $value->getLine(), null];
            $properties[] = ['previous', 'private', $value->getPrevious(), null];

            return $properties;
        }

        if (method_exists($value, '__debugInfo')) {
            foreach ((array) $value->__debugInfo() as $name => $item) {
                $properties[] = [(string) $name, 'public', $item, null];
            }

            return $properties;
        }

        foreach ((array) $value as $key => $item) {
            $key = (string) $key;

            if (!str_starts_with($key, "\0")) {
                $properties[] = [$key, 'public', $item, null];
                continue;
            }

            $parts = explode("\0", $key);
            $class = $parts[1] ?? '';
            $name = $parts[2] ?? $key;

            if ($class === '*') {
                $properties[] = [$name, 'protected', $item, null];
            } else {
                $properties[] = [$name, 'private', $item, $class !== $value::class ? $class : null];
            }
        }

        return $properties;
    }

    protected function closureProperties(Closure $closure): array
    {
        $reflection = new ReflectionFunction($closure);
        $parameters = [];

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            $parameters[] = trim(($type instanceof ReflectionNamedType ? $type->getName() : (string) $type) . ' $' . $parameter->getName());
        }

        $properties = [];
        $scope = $reflection->getClosureScopeClass();

        if ($scope !== null) {
            $properties[] = ['class', 'virtual', $scope->getName(), null];
        }

        $properties[] = ['parameters', 'virtual', '(' . implode(', ', $parameters) . ')', null];

        if ($reflection->getFileName() !== false) {
            $properties[] = ['file', 'virtual', $reflection->getFileName(), null];
            $properties[] = ['line', 'virtual', $reflection->getStartLine() . ' to ' . $reflection->getEndLine(), null];
        }

        return $properties;
    }

    protected function resourceNode(mixed $value): array
    {
        $type = get_debug_type($value);

        return [
            'type' => 'resource',
            'kind' => str_contains($type, 'closed') ? 'closed' : (is_resource($value) ? get_resource_type($value) : 'unknown'),
            'id' => (int) $value,
        ];
    }
}