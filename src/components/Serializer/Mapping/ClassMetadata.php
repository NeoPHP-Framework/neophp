<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Mapping;

use ReflectionClass;

class ClassMetadata
{
    public array $properties = [];

    public array $groups = [];

    public array $constructorParameters = [];

    public function __construct(public string $class, public ReflectionClass $reflection)
    {
    }

    public function getProperty(string $name): ?PropertyMetadata
    {
        return $this->properties[$name] ?? null;
    }

    public function property(string $name): PropertyMetadata
    {
        return $this->properties[$name] ??= new PropertyMetadata($name);
    }

    public function getProperties(): array
    {
        return $this->properties;
    }
}