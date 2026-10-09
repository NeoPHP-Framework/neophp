<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Proxy;

use NeoPHP\Package\Orm\Contract\ProxyInterface;
use NeoPHP\Package\Orm\Exception\OrmException;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use UnitEnum;

class ProxyGenerator
{
    public const NAMESPACE = 'NeoProxies\\__CG__';

    public const RESERVED = ['__construct', '__destruct', '__get', '__set', '__isset', '__unset', '__clone', '__sleep', '__wakeup', '__serialize', '__unserialize'];

    public static function getProxyClass(string $class): string
    {
        return self::NAMESPACE . '\\' . ltrim($class, '\\');
    }

    public function canProxy(string $class): bool
    {
        $reflection = new ReflectionClass($class);

        if ($reflection->isFinal() || $reflection->isAbstract() || $reflection->isReadOnly()) {
            return false;
        }

        foreach (['__get', '__set', '__isset', '__unset'] as $magic) {
            if ($reflection->hasMethod($magic)) {
                return false;
            }
        }

        foreach ($reflection->getProperties() as $property) {
            if ($property->isReadOnly()) {
                return false;
            }
        }

        try {
            $this->generate($class, '');
        } catch (OrmException) {
            return false;
        }

        return true;
    }

    public function generate(string $class, string $identifier): string
    {
        $reflection = new ReflectionClass($class);
        $proxyClass = self::getProxyClass($class);
        $position = strrpos($proxyClass, '\\');
        $namespace = substr($proxyClass, 0, (int) $position);
        $shortName = substr($proxyClass, (int) $position + 1);
        $lazy = [];

        foreach ($reflection->getProperties() as $property) {
            if ($property->isPublic() && !$property->isStatic()) {
                $lazy[] = var_export($property->getName(), true) . ' => true';
            }
        }

        $methods = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isFinal() || $method->isAbstract() || in_array(strtolower($method->getName()), self::RESERVED, true) || str_starts_with($method->getName(), '__neo')) {
                continue;
            }

            $methods[] = $this->generateMethod($method, $reflection, $identifier);
        }

        $clone = $reflection->hasMethod('__clone') ? "\n        parent::__clone();" : '';

        return '<?php' . "\n\n"
            . 'declare(strict_types=1);' . "\n\n"
            . 'namespace ' . $namespace . ';' . "\n\n"
            . 'class ' . $shortName . ' extends \\' . $reflection->getName() . ' implements \\' . ProxyInterface::class . "\n"
            . "{\n"
            . '    public static array $__neoLazyProperties__ = [' . implode(', ', $lazy) . '];' . "\n\n"
            . '    public ?\Closure $__neoInitializer__ = null;' . "\n\n"
            . '    public bool $__neoInitialized__ = false;' . "\n\n"
            . <<<'PHP'
    public function __neoLoad(): void
    {
        if ($this->__neoInitialized__) {
            return;
        }

        $this->__neoInitialized__ = true;
        $initializer = $this->__neoInitializer__;

        if ($initializer === null) {
            return;
        }

        try {
            $initializer($this);
            $this->__neoInitializer__ = null;
        } catch (\Throwable $exception) {
            $this->__neoInitialized__ = false;

            throw $exception;
        }
    }

    public function __neoIsInitialized(): bool
    {
        return $this->__neoInitialized__;
    }

    public function __neoSetInitialized(bool $initialized): void
    {
        $this->__neoInitialized__ = $initialized;

        if ($initialized) {
            $this->__neoInitializer__ = null;
        }
    }

    public function __neoSetInitializer(?\Closure $initializer): void
    {
        $this->__neoInitializer__ = $initializer;
    }

    public function __neoUnsetLazyProperties(): void
    {
        foreach (static::$__neoLazyProperties__ as $name => $lazy) {
            unset($this->$name);
        }
    }

    public function __get(string $name): mixed
    {
        if (isset(static::$__neoLazyProperties__[$name])) {
            $this->__neoLoad();

            return $this->$name;
        }

        trigger_error(sprintf('Undefined property: %s::$%s', parent::class, $name), E_USER_WARNING);

        return null;
    }

    public function __set(string $name, mixed $value): void
    {
        if (isset(static::$__neoLazyProperties__[$name])) {
            $this->__neoLoad();
        }

        $this->$name = $value;
    }

    public function __isset(string $name): bool
    {
        if (isset(static::$__neoLazyProperties__[$name])) {
            $this->__neoLoad();

            return isset($this->$name);
        }

        return false;
    }

    public function __unset(string $name): void
    {
        if (isset(static::$__neoLazyProperties__[$name])) {
            $this->__neoLoad();
        }

        unset($this->$name);
    }

    public function __clone()
    {
        $this->__neoLoad();
PHP
            . $clone . "\n    }\n"
            . ($methods === [] ? '' : "\n" . implode("\n", $methods))
            . "}\n";
    }

    protected function generateMethod(ReflectionMethod $method, ReflectionClass $class, string $identifier): string
    {
        $name = $method->getName();
        $parameters = [];
        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            $parameters[] = $this->generateParameter($parameter, $class);
            $arguments[] = ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName();
        }

        $returnType = $method->hasReturnType() ? $this->renderType($method->getReturnType(), $class) : '';
        $call = 'parent::' . $name . '(' . implode(', ', $arguments) . ')';
        $body = in_array($returnType, ['void', 'never'], true) ? '        ' . $call . ';' : '        return ' . $call . ';';
        $shortcut = '';

        if ($identifier !== '' && $method->getNumberOfParameters() === 0 && in_array(strtolower($name), [strtolower('get' . $identifier), strtolower($identifier)], true)) {
            $shortcut = "        if (\$this->__neoInitialized__ === false) {\n            return " . $call . ";\n        }\n\n";
        }

        return '    public function ' . ($method->returnsReference() ? '&' : '') . $name . '(' . implode(', ', $parameters) . ')' . ($returnType !== '' ? ': ' . $returnType : '') . "\n"
            . "    {\n"
            . $shortcut
            . "        \$this->__neoLoad();\n\n"
            . $body . "\n"
            . "    }\n";
    }

    protected function generateParameter(ReflectionParameter $parameter, ReflectionClass $class): string
    {
        $code = $parameter->hasType() ? $this->renderType($parameter->getType(), $class) . ' ' : '';
        $code .= ($parameter->isPassedByReference() ? '&' : '') . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName();

        if ($parameter->isDefaultValueAvailable()) {
            $code .= ' = ' . $this->renderDefault($parameter, $class);
        }

        return $code;
    }

    protected function renderDefault(ReflectionParameter $parameter, ReflectionClass $class): string
    {
        if ($parameter->isDefaultValueConstant()) {
            $constant = (string) $parameter->getDefaultValueConstantName();

            if (str_starts_with($constant, 'self::') || str_starts_with($constant, 'static::')) {
                return '\\' . $class->getName() . substr($constant, (int) strpos($constant, '::'));
            }

            return str_contains($constant, '::') || str_contains($constant, '\\') ? '\\' . ltrim($constant, '\\') : $constant;
        }

        $value = $parameter->getDefaultValue();

        if (is_object($value) && !$value instanceof UnitEnum) {
            throw new OrmException('Unable to generate a proxy for "{class}": the default value of ${parameter} is an object.', 0, null, ['class' => $class->getName(), 'parameter' => $parameter->getName()]);
        }

        if ($value instanceof UnitEnum) {
            return '\\' . $value::class . '::' . $value->name;
        }

        return str_replace("\n", '', var_export($value, true));
    }

    protected function renderType(?ReflectionType $type, ReflectionClass $class): string
    {
        if ($type === null) {
            return '';
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(fn (ReflectionType $inner): string => $inner instanceof ReflectionIntersectionType ? '(' . $this->renderType($inner, $class) . ')' : $this->renderType($inner, $class), $type->getTypes()));
        }

        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map(fn (ReflectionType $inner): string => $this->renderType($inner, $class), $type->getTypes()));
        }

        if (!$type instanceof ReflectionNamedType) {
            return (string) $type;
        }

        $name = $type->getName();
        $rendered = match (true) {
            $name === 'self' => '\\' . $class->getName(),
            $name === 'static', $type->isBuiltin() => $name,
            default => '\\' . ltrim($name, '\\'),
        };

        return $type->allowsNull() && !in_array($name, ['mixed', 'null'], true) ? '?' . $rendered : $rendered;
    }
}