<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Mapping;

use NeoPHP\Component\Serializer\Attribute\Context;
use NeoPHP\Component\Serializer\Attribute\Groups;
use NeoPHP\Component\Serializer\Attribute\Ignore;
use NeoPHP\Component\Serializer\Attribute\MaxDepth;
use NeoPHP\Component\Serializer\Attribute\SerializedName;
use NeoPHP\Component\Serializer\Attribute\Type;
use NeoPHP\Component\Serializer\Exception\SerializerException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

class MetadataFactory
{
    public const GETTER_PREFIXES = ['get', 'is', 'has'];

    protected array $metadata = [];

    public function getMetadata(string|object $class): ClassMetadata
    {
        $class = ClassResolver::getRealClass($class);

        return $this->metadata[$class] ??= $this->load($class);
    }

    public function hasMetadata(string $class): bool
    {
        return isset($this->metadata[ClassResolver::getRealClass($class)]);
    }

    public function clear(): void
    {
        $this->metadata = [];
    }

    protected function load(string $class): ClassMetadata
    {
        if (!class_exists($class) && !interface_exists($class)) {
            throw new SerializerException('The class "{class}" does not exist.', 0, null, ['class' => $class]);
        }

        $reflection = new ReflectionClass($class);
        $metadata = new ClassMetadata($class, $reflection);

        for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
            foreach ($current->getAttributes(Groups::class) as $attribute) {
                $metadata->groups = array_values(array_unique([...$metadata->groups, ...array_map('strval', $attribute->newInstance()->groups)]));
            }
        }

        foreach ($this->collectProperties($reflection) as $property) {
            $this->loadProperty($metadata, $property);
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $this->loadMethod($metadata, $method);
        }

        $constructor = $reflection->getConstructor();

        if ($constructor !== null && $constructor->isPublic()) {
            foreach ($constructor->getParameters() as $parameter) {
                $this->loadParameter($metadata, $parameter);
            }
        }

        foreach ($metadata->properties as $property) {
            if ($property->groups === [] && $metadata->groups !== []) {
                $property->groups = $metadata->groups;
            }
        }

        return $metadata;
    }

    protected function collectProperties(ReflectionClass $reflection): array
    {
        $levels = [];

        for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
            $level = [];

            foreach ($current->getProperties() as $property) {
                if (!$property->isStatic() && $property->getDeclaringClass()->getName() === $current->getName()) {
                    $level[$property->getName()] = $property;
                }
            }

            array_unshift($levels, $level);
        }

        $properties = [];

        foreach ($levels as $level) {
            foreach ($level as $name => $property) {
                $properties[$name] = $property;
            }
        }

        return $properties;
    }

    protected function loadProperty(ClassMetadata $metadata, ReflectionProperty $reflection): void
    {
        $property = $metadata->property($reflection->getName());
        $property->propertyType = $reflection->getType();
        $property->publicRead = $reflection->isPublic();
        $property->publicWrite = $reflection->isPublic() && !$reflection->isReadOnly();
        $this->readAttributes($property, $reflection);
    }

    protected function loadMethod(ClassMetadata $metadata, ReflectionMethod $method): void
    {
        if ($method->isStatic() || str_starts_with($method->getName(), '__')) {
            return;
        }

        $name = $method->getName();

        if (str_starts_with($name, 'set') && strlen($name) > 3 && $method->getNumberOfParameters() >= 1 && $method->getNumberOfRequiredParameters() <= 1) {
            $property = $metadata->property(lcfirst(substr($name, 3)));
            $property->setter = $name;
            $property->setterType = $method->getParameters()[0]->getType();

            return;
        }

        if ($method->getNumberOfRequiredParameters() > 0) {
            return;
        }

        foreach (self::GETTER_PREFIXES as $prefix) {
            if (!str_starts_with($name, $prefix) || strlen($name) <= strlen($prefix) || !ctype_upper($name[strlen($prefix)])) {
                continue;
            }

            $returnType = $method->getReturnType();

            if ($returnType !== null && in_array((string) $returnType, ['void', 'never'], true)) {
                return;
            }

            $property = $metadata->property(lcfirst(substr($name, strlen($prefix))));

            if ($property->getter === null) {
                $property->getter = $name;
                $property->propertyType ??= $returnType;
                $this->readAttributes($property, $method);
            }

            return;
        }
    }

    protected function loadParameter(ClassMetadata $metadata, ReflectionParameter $parameter): void
    {
        $property = $metadata->property($parameter->getName());
        $property->constructorArgument = true;
        $metadata->constructorParameters[$parameter->getName()] = $parameter;

        if (!$parameter->isPromoted()) {
            $property->propertyType ??= $parameter->getType();
            $this->readAttributes($property, $parameter);
        }
    }

    protected function readAttributes(PropertyMetadata $property, ReflectionProperty|ReflectionMethod|ReflectionParameter $reflection): void
    {
        foreach ($reflection->getAttributes(Groups::class) as $attribute) {
            $property->addGroups($attribute->newInstance()->groups);
        }

        foreach ($reflection->getAttributes(SerializedName::class) as $attribute) {
            $property->serializedName = $attribute->newInstance()->name;
        }

        if ($reflection->getAttributes(Ignore::class) !== []) {
            $property->ignored = true;
        }

        foreach ($reflection->getAttributes(MaxDepth::class) as $attribute) {
            $property->maxDepth = $attribute->newInstance()->maxDepth;
        }

        foreach ($reflection->getAttributes(Type::class) as $attribute) {
            $property->type = $attribute->newInstance()->type;
        }

        foreach ($reflection->getAttributes(Context::class) as $attribute) {
            $context = $attribute->newInstance();
            $property->contexts[] = [
                'context' => $context->context,
                'normalization' => $context->normalization,
                'denormalization' => $context->denormalization,
                'groups' => array_map('strval', $context->groups),
            ];
        }
    }
}