<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Metadata;

class NamingStrategy
{
    public function classToTableName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $this->toSnakeCase($position === false ? $class : substr($class, $position + 1));
    }

    public function propertyToColumnName(string $property): string
    {
        return $this->toSnakeCase($property);
    }

    public function joinColumnName(string $property, string $referencedColumn = 'id'): string
    {
        return $this->toSnakeCase($property) . '_' . $referencedColumn;
    }

    public function joinTableName(string $source, string $target): string
    {
        return $this->classToTableName($source) . '_' . $this->classToTableName($target);
    }

    public function joinKeyColumnName(string $class, string $referencedColumn = 'id'): string
    {
        return $this->classToTableName($class) . '_' . $referencedColumn;
    }

    public function toSnakeCase(string $name): string
    {
        return strtolower((string) preg_replace(['/([a-z\d])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/'], '$1_$2', $name));
    }
}