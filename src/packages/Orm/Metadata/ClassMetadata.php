<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Metadata;

use NeoPHP\Package\Orm\Exception\MappingException;
use ReflectionClass;
use ReflectionProperty;

class ClassMetadata
{
    public const MANY_TO_ONE = 'ManyToOne';
    public const ONE_TO_ONE = 'OneToOne';
    public const ONE_TO_MANY = 'OneToMany';
    public const MANY_TO_MANY = 'ManyToMany';

    public const TO_ONE = [self::MANY_TO_ONE, self::ONE_TO_ONE];
    public const TO_MANY = [self::ONE_TO_MANY, self::MANY_TO_MANY];

    public string $name;

    public string $table;

    public ?string $repository = null;

    public string $identifier = '';

    public string $generator = 'none';

    public array $fields = [];

    public array $associations = [];

    public array $columns = [];

    public array $indexes = [];

    public array $callbacks = [];

    public ReflectionClass $reflection;

    protected array $properties = [];

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->reflection = new ReflectionClass($name);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getIdentifierColumn(): string
    {
        return $this->fields[$this->identifier]['column'];
    }

    public function getIdentifierType(): string
    {
        return $this->fields[$this->identifier]['type'];
    }

    public function isIdGenerated(): bool
    {
        return $this->generator === 'auto';
    }

    public function hasField(string $field): bool
    {
        return isset($this->fields[$field]);
    }

    public function hasAssociation(string $field): bool
    {
        return isset($this->associations[$field]);
    }

    public function getField(string $field): array
    {
        return $this->fields[$field] ?? throw new MappingException('The entity "{class}" has no field "{field}".', 0, null, ['class' => $this->name, 'field' => $field]);
    }

    public function getAssociation(string $field): array
    {
        return $this->associations[$field] ?? throw new MappingException('The entity "{class}" has no association "{field}".', 0, null, ['class' => $this->name, 'field' => $field]);
    }

    public function getColumnName(string $field): string
    {
        if (isset($this->fields[$field])) {
            return $this->fields[$field]['column'];
        }

        $association = $this->associations[$field] ?? null;

        if ($association !== null && $association['owning'] && in_array($association['type'], self::TO_ONE, true)) {
            return $association['joinColumn'];
        }

        throw new MappingException('The field "{field}" of the entity "{class}" is not mapped to a column.', 0, null, ['class' => $this->name, 'field' => $field]);
    }

    public function getOwningToOneAssociations(): array
    {
        return array_filter($this->associations, static fn (array $association): bool => $association['owning'] && in_array($association['type'], self::TO_ONE, true));
    }

    public function newInstance(): object
    {
        return $this->reflection->newInstanceWithoutConstructor();
    }

    public function addProperty(string $field, ReflectionProperty $property): void
    {
        $this->properties[$field] = $property;
    }

    public function getProperty(string $field): ReflectionProperty
    {
        return $this->properties[$field] ?? throw new MappingException('The entity "{class}" has no mapped property "{field}".', 0, null, ['class' => $this->name, 'field' => $field]);
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getValue(object $entity, string $field): mixed
    {
        $property = $this->getProperty($field);

        return $property->isInitialized($entity) ? $property->getValue($entity) : null;
    }

    public function setValue(object $entity, string $field, mixed $value): void
    {
        $this->getProperty($field)->setValue($entity, $value);
    }

    public function getIdentifierValue(object $entity): mixed
    {
        return $this->getValue($entity, $this->identifier);
    }

    public function getCallbacks(string $event): array
    {
        return $this->callbacks[$event] ?? [];
    }
}