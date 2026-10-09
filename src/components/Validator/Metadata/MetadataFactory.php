<?php

declare(strict_types=1);

namespace NeoPHP\Component\Validator\Metadata;

use NeoPHP\Component\Validator\Contract\ConstraintInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;

class MetadataFactory
{
    protected array $metadata = [];

    public function get(string $class): array
    {
        return $this->metadata[$class] ??= $this->load($class);
    }

    public static function value(ReflectionProperty $property, object $object): mixed
    {
        return $property->isInitialized($object) ? $property->getValue($object) : null;
    }

    protected function load(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $classConstraints = [];
        $properties = [];
        $hierarchy = [];

        for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
            array_unshift($hierarchy, $current);
        }

        foreach ($hierarchy as $current) {
            array_push($classConstraints, ...$this->constraints($current->getAttributes(ConstraintInterface::class, ReflectionAttribute::IS_INSTANCEOF)));

            foreach ($current->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $current->getName() || $property->isStatic()) {
                    continue;
                }

                $constraints = $this->constraints($property->getAttributes(ConstraintInterface::class, ReflectionAttribute::IS_INSTANCEOF));

                if ($constraints !== []) {
                    $properties[$property->getName()] = [
                        'property' => $property,
                        'constraints' => [...($properties[$property->getName()]['constraints'] ?? []), ...$constraints],
                    ];
                }
            }
        }

        return ['class' => $classConstraints, 'properties' => $properties];
    }

    protected function constraints(array $attributes): array
    {
        return array_map(static fn (ReflectionAttribute $attribute): ConstraintInterface => $attribute->newInstance(), $attributes);
    }
}