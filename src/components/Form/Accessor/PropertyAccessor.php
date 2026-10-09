<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Accessor;

use ArrayAccess;
use NeoPHP\Component\Form\Exception\FormException;
use NeoPHP\Component\Form\Exception\InvalidTypeException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use Traversable;
use TypeError;

class PropertyAccessor
{
    public function getValue(mixed $data, string $path): mixed
    {
        foreach ($this->segments($path) as $segment) {
            if ($data === null) {
                return null;
            }

            $data = $this->read($data, $segment);
        }

        return $data;
    }

    public function setValue(mixed &$data, string $path, mixed $value): void
    {
        $segments = $this->segments($path);
        $last = array_pop($segments);
        $target = &$data;

        foreach ($segments as $segment) {
            if (is_array($target)) {
                $target[$segment] ??= [];
                $target = &$target[$segment];
                continue;
            }

            $next = $this->read($target, $segment);

            if (!is_object($next)) {
                throw new FormException('Unable to write "{path}": "{segment}" is not an object.', 0, null, ['path' => $path, 'segment' => $segment]);
            }

            unset($target);
            $target = $next;
        }

        $this->write($target, $last, $value);
    }

    public function isWritable(mixed $data, string $path): bool
    {
        if (is_array($data) || $data instanceof ArrayAccess) {
            return true;
        }

        if (!is_object($data)) {
            return false;
        }

        $segments = $this->segments($path);
        $name = (string) end($segments);

        return method_exists($data, 'set' . ucfirst($name)) || property_exists($data, $name);
    }

    public function read(mixed $data, string $name): mixed
    {
        if (is_array($data)) {
            return $data[$name] ?? null;
        }

        if (!is_object($data)) {
            return null;
        }

        $studly = ucfirst($name);

        foreach (['get' . $studly, 'is' . $studly, 'has' . $studly, $name] as $method) {
            if (method_exists($data, $method) && (new ReflectionMethod($data, $method))->isPublic() && (new ReflectionMethod($data, $method))->getNumberOfRequiredParameters() === 0) {
                return $data->$method();
            }
        }

        $property = $this->property($data, $name);

        if ($property !== null) {
            return $property->isInitialized($data) ? $property->getValue($data) : null;
        }

        if ($data instanceof ArrayAccess) {
            return $data[$name] ?? null;
        }

        if (isset($data->$name)) {
            return $data->$name;
        }

        return null;
    }

    public function write(mixed &$data, string $name, mixed $value): void
    {
        if (is_array($data)) {
            $data[$name] = $value;

            return;
        }

        if (!is_object($data)) {
            throw new FormException('Unable to write the property "{property}" on a value of type "{type}".', 0, null, ['property' => $name, 'type' => get_debug_type($data)]);
        }

        if ($this->writeCollection($data, $name, $value)) {
            return;
        }

        $setter = 'set' . ucfirst($name);

        try {
            if (method_exists($data, $setter) && (new ReflectionMethod($data, $setter))->isPublic()) {
                $data->$setter($value);

                return;
            }

            $property = $this->property($data, $name);

            if ($property !== null) {
                $property->setValue($data, $value);

                return;
            }

            if ($data instanceof ArrayAccess) {
                $data[$name] = $value;

                return;
            }

            $data->$name = $value;
        } catch (TypeError $exception) {
            throw new InvalidTypeException('Unable to write the value of type "{type}" into "{property}".', 0, $exception, [
                'type' => get_debug_type($value),
                'property' => $name,
                'null' => $value === null,
            ]);
        }
    }

    public function getPropertyType(object|string $data, string $name): ?ReflectionNamedType
    {
        $property = $this->property($data, $name);
        $type = $property?->getType();

        return $type instanceof ReflectionNamedType ? $type : null;
    }

    public static function singularize(string $name): string
    {
        return match (true) {
            str_ends_with($name, 'ies') => substr($name, 0, -3) . 'y',
            str_ends_with($name, 'sses'), str_ends_with($name, 'xes'), str_ends_with($name, 'ches'), str_ends_with($name, 'shes') => substr($name, 0, -2),
            str_ends_with($name, 's') && !str_ends_with($name, 'ss') => substr($name, 0, -1),
            default => $name,
        };
    }

    protected function writeCollection(object $data, string $name, mixed $value): bool
    {
        if (!is_iterable($value)) {
            return false;
        }

        $current = $this->read($data, $name);

        if ($current === $value || !is_object($current) || !is_iterable($current)) {
            return false;
        }

        $new = $value instanceof Traversable ? iterator_to_array($value, false) : array_values($value);
        $old = iterator_to_array($current, false);
        $singular = self::singularize($name);
        $adder = 'add' . ucfirst($singular);
        $remover = 'remove' . ucfirst($singular);
        $hasMethods = method_exists($data, $adder) && method_exists($data, $remover);

        if (!$hasMethods && !method_exists($current, 'add') && !method_exists($current, 'removeElement')) {
            return false;
        }

        foreach ($old as $item) {
            if (!in_array($item, $new, true)) {
                $hasMethods ? $data->$remover($item) : $current->removeElement($item);
            }
        }

        foreach ($new as $item) {
            if (!in_array($item, $old, true)) {
                $hasMethods ? $data->$adder($item) : $current->add($item);
            }
        }

        return true;
    }

    protected function property(object|string $data, string $name): ?ReflectionProperty
    {
        try {
            for ($class = new ReflectionClass($data); $class !== false; $class = $class->getParentClass()) {
                if ($class->hasProperty($name)) {
                    $property = $class->getProperty($name);

                    return $property->isStatic() ? null : $property;
                }
            }
        } catch (ReflectionException) {
            return null;
        }

        return null;
    }

    protected function segments(string $path): array
    {
        $path = str_replace(['[', ']'], ['.', ''], $path);

        return array_values(array_filter(explode('.', $path), static fn (string $segment): bool => $segment !== ''));
    }
}