<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Reflection;

use Closure;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

class ControllerReflector
{
    protected static array $methods = [];

    public static function target(mixed $controller): array
    {
        if (is_string($controller) && str_contains($controller, '::')) {
            return explode('::', $controller, 2);
        }

        if (is_string($controller)) {
            return [$controller, '__invoke'];
        }

        if (is_array($controller) && count($controller) === 2 && isset($controller[0], $controller[1])) {
            return [is_object($controller[0]) ? $controller[0]::class : (string) $controller[0], (string) $controller[1]];
        }

        if (is_object($controller) && !$controller instanceof Closure) {
            return [$controller::class, '__invoke'];
        }

        return [null, null];
    }

    public static function method(mixed $controller): ?ReflectionMethod
    {
        [$class, $method] = self::target($controller);

        if ($class === null || $method === null) {
            return null;
        }

        $key = $class . '::' . $method;

        if (array_key_exists($key, self::$methods)) {
            return self::$methods[$key];
        }

        try {
            $reflection = class_exists($class) ? new ReflectionMethod($class, $method) : null;
        } catch (ReflectionException) {
            $reflection = null;
        }

        return self::$methods[$key] = $reflection;
    }

    public static function attributes(mixed $controller, string $attribute): array
    {
        $method = self::method($controller);

        if ($method === null) {
            return [];
        }

        return [
            ...self::instances(new ReflectionClass((string) self::target($controller)[0]), $attribute),
            ...array_map(static fn (ReflectionAttribute $item): object => $item->newInstance(), $method->getAttributes($attribute, ReflectionAttribute::IS_INSTANCEOF)),
        ];
    }

    protected static function instances(ReflectionClass $class, string $attribute): array
    {
        $instances = [];
        $current = $class;

        while ($current !== false) {
            $attributes = $current->getAttributes($attribute, ReflectionAttribute::IS_INSTANCEOF);

            if ($attributes !== []) {
                foreach ($attributes as $item) {
                    $instances[] = $item->newInstance();
                }

                break;
            }

            $current = $current->getParentClass();
        }

        return $instances;
    }
}