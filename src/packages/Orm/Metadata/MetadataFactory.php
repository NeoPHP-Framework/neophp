<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Metadata;

use BackedEnum;
use NeoPHP\Component\Kernel\Discovery\ClassFinder;
use NeoPHP\Package\Orm\Contract\ProxyInterface;
use NeoPHP\Package\Orm\Exception\MappingException;
use NeoPHP\Package\Orm\Mapping\Column;
use NeoPHP\Package\Orm\Mapping\Entity;
use NeoPHP\Package\Orm\Mapping\GeneratedValue;
use NeoPHP\Package\Orm\Mapping\Id;
use NeoPHP\Package\Orm\Mapping\Index;
use NeoPHP\Package\Orm\Mapping\ManyToMany;
use NeoPHP\Package\Orm\Mapping\ManyToOne;
use NeoPHP\Package\Orm\Mapping\MappedSuperclass;
use NeoPHP\Package\Orm\Mapping\OneToMany;
use NeoPHP\Package\Orm\Mapping\OneToOne;
use NeoPHP\Package\Orm\Mapping\PostLoad;
use NeoPHP\Package\Orm\Mapping\PostPersist;
use NeoPHP\Package\Orm\Mapping\PostRemove;
use NeoPHP\Package\Orm\Mapping\PostUpdate;
use NeoPHP\Package\Orm\Mapping\PrePersist;
use NeoPHP\Package\Orm\Mapping\PreRemove;
use NeoPHP\Package\Orm\Mapping\PreUpdate;
use NeoPHP\Package\Orm\Type\Type;
use ReflectionClass;
use ReflectionProperty;

class MetadataFactory
{
    public const CALLBACKS = [
        PrePersist::class => 'prePersist',
        PostPersist::class => 'postPersist',
        PreUpdate::class => 'preUpdate',
        PostUpdate::class => 'postUpdate',
        PreRemove::class => 'preRemove',
        PostRemove::class => 'postRemove',
        PostLoad::class => 'postLoad',
    ];

    protected array $metadata = [];

    protected array $loading = [];

    protected ?array $entityClasses = null;

    public function __construct(protected array $paths = [], protected NamingStrategy $naming = new NamingStrategy())
    {
    }

    public function getNamingStrategy(): NamingStrategy
    {
        return $this->naming;
    }

    public function getPaths(): array
    {
        return $this->paths;
    }

    public function getMetadata(string|object $class): ClassMetadata
    {
        $class = self::getRealClass(is_object($class) ? $class::class : ltrim($class, '\\'));

        if (isset($this->metadata[$class])) {
            return $this->metadata[$class];
        }

        return $this->load($class);
    }

    public function isEntity(string|object $class): bool
    {
        $class = self::getRealClass(is_object($class) ? $class::class : ltrim($class, '\\'));

        if (isset($this->metadata[$class])) {
            return true;
        }

        if (!class_exists($class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        return !$reflection->isAbstract() && $reflection->getAttributes(Entity::class) !== [];
    }

    public function getEntityClasses(): array
    {
        if ($this->entityClasses !== null) {
            return $this->entityClasses;
        }

        $finder = new ClassFinder();
        $classes = [];

        foreach ($this->paths as $path) {
            foreach ($finder->find((string) $path, ['Entity']) as $class) {
                if (class_exists($class) && $this->isEntity($class)) {
                    $classes[] = $class;
                }
            }
        }

        sort($classes);

        return $this->entityClasses = array_values(array_unique($classes));
    }

    public function getAllMetadata(): array
    {
        return array_map(fn (string $class): ClassMetadata => $this->getMetadata($class), $this->getEntityClasses());
    }

    public static function getRealClass(string $class): string
    {
        if (is_subclass_of($class, ProxyInterface::class)) {
            return (string) get_parent_class($class);
        }

        return $class;
    }

    protected function load(string $class): ClassMetadata
    {
        if (!class_exists($class)) {
            throw new MappingException('The class "{class}" does not exist.', 0, null, ['class' => $class]);
        }

        $metadata = new ClassMetadata($class);
        $attributes = $metadata->reflection->getAttributes(Entity::class);

        if ($attributes === []) {
            throw new MappingException('The class "{class}" is not an entity: add the #[ORM\Entity] attribute.', 0, null, ['class' => $class]);
        }

        if ($metadata->reflection->isAbstract()) {
            throw new MappingException('The entity "{class}" is abstract: replace #[ORM\Entity] by #[ORM\MappedSuperclass] to share its mapping with the entities extending it.', 0, null, ['class' => $class]);
        }

        if ($metadata->reflection->getAttributes(MappedSuperclass::class) !== []) {
            throw new MappingException('The class "{class}" cannot be both an entity and a mapped superclass.', 0, null, ['class' => $class]);
        }

        $entity = $attributes[0]->newInstance();
        $metadata->table = $entity->table ?? $this->naming->classToTableName($class);
        $metadata->repository = $entity->repository;

        foreach ($this->collectProperties($metadata->reflection) as $property) {
            $this->loadProperty($metadata, $property);
        }

        if ($metadata->identifier === '') {
            throw new MappingException('The entity "{class}" has no identifier: add #[ORM\Id] on a property.', 0, null, ['class' => $class]);
        }

        $metadata->callbacks = $this->collectCallbacks($metadata->reflection);

        $this->metadata[$class] = $metadata;

        foreach ($metadata->associations as $field => $association) {
            $metadata->associations[$field] = $this->resolveAssociation($metadata, $association);
        }

        foreach ($this->collectIndexes($metadata->reflection) as [$attribute, $inherited]) {
            $index = $attribute->newInstance();
            $index->name = $inherited ? null : $index->name;
            $columns = array_map(fn (string $column): string => $metadata->hasField($column) || $metadata->hasAssociation($column) ? $metadata->getColumnName($column) : $column, $index->columns);
            $metadata->indexes[] = ['name' => $index->name, 'columns' => $columns, 'unique' => $index->unique];
        }

        return $metadata;
    }

    protected function hierarchy(ReflectionClass $reflection): array
    {
        $levels = [];

        for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
            array_unshift($levels, $current);
        }

        return $levels;
    }

    protected function collectCallbacks(ReflectionClass $reflection): array
    {
        $methods = [];

        foreach ($this->hierarchy($reflection) as $level) {
            foreach ($level->getMethods() as $method) {
                if ($method->isStatic() || $method->getDeclaringClass()->getName() !== $level->getName()) {
                    continue;
                }

                $key = $method->isPrivate() ? $level->getName() . '::' . $method->getName() : $method->getName();
                unset($methods[$key]);
                $methods[$key] = $method;
            }
        }

        $callbacks = [];

        foreach ($methods as $method) {
            foreach (self::CALLBACKS as $attribute => $event) {
                if ($method->getAttributes($attribute) !== []) {
                    $callbacks[$event][] = $method->getDeclaringClass()->getName() . '::' . $method->getName();
                }
            }
        }

        return $callbacks;
    }

    protected function collectIndexes(ReflectionClass $reflection): array
    {
        $indexes = [];

        foreach ($this->hierarchy($reflection) as $level) {
            foreach ($level->getAttributes(Index::class) as $attribute) {
                $indexes[] = [$attribute, $level->getName() !== $reflection->getName()];
            }
        }

        return $indexes;
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

    protected function loadProperty(ClassMetadata $metadata, ReflectionProperty $property): void
    {
        $name = $property->getName();

        foreach ([ManyToOne::class, OneToOne::class, OneToMany::class, ManyToMany::class] as $relation) {
            $attributes = $property->getAttributes($relation);

            if ($attributes !== []) {
                $metadata->associations[$name] = $this->buildAssociation($metadata, $name, $attributes[0]->newInstance());
                $metadata->addProperty($name, $property);

                return;
            }
        }

        $columns = $property->getAttributes(Column::class);
        $isId = $property->getAttributes(Id::class) !== [];

        if ($columns === [] && !$isId) {
            return;
        }

        $column = $columns !== [] ? $columns[0]->newInstance() : new Column();
        [$inferredType, $inferredEnum] = Type::infer($property);
        $generated = $property->getAttributes(GeneratedValue::class);
        $strategy = $generated !== [] ? $generated[0]->newInstance()->strategy : GeneratedValue::NONE;
        $type = Type::normalize($column->type ?? ($strategy === GeneratedValue::UUID ? Type::GUID : $inferredType));
        $enumType = $column->enumType ?? $inferredEnum;

        $field = [
            'field' => $name,
            'column' => $column->name ?? $this->naming->propertyToColumnName($name),
            'type' => $type,
            'nullable' => $column->nullable ?? false,
            'length' => $column->length ?? (Type::getSchemaType($type) === Type::STRING ? 255 : null),
            'unique' => $column->unique,
            'default' => $column->default instanceof BackedEnum ? $column->default->value : $column->default,
            'precision' => $column->precision ?? (Type::getSchemaType($type) === Type::DECIMAL ? 10 : null),
            'scale' => $column->scale ?? (Type::getSchemaType($type) === Type::DECIMAL ? 2 : null),
            'enumType' => $enumType,
            'id' => $isId,
        ];

        if ($isId) {
            if ($metadata->identifier !== '') {
                throw new MappingException('The entity "{class}" has several identifiers: composite identifiers are not supported.', 0, null, ['class' => $metadata->name]);
            }

            $metadata->identifier = $name;
            $metadata->generator = $strategy;
            $field['nullable'] = false;
        }

        if (isset($metadata->columns[$field['column']])) {
            throw new MappingException('The column "{column}" is mapped twice in the entity "{class}".', 0, null, ['class' => $metadata->name, 'column' => $field['column']]);
        }

        $metadata->fields[$name] = $field;
        $metadata->columns[$field['column']] = $name;
        $metadata->addProperty($name, $property);
    }

    protected function buildAssociation(ClassMetadata $metadata, string $field, object $attribute): array
    {
        $target = ltrim($attribute->target, '\\');

        if (!class_exists($target)) {
            $namespace = $metadata->reflection->getNamespaceName();
            $target = class_exists($namespace . '\\' . $target) ? $namespace . '\\' . $target : $target;
        }

        $type = (new ReflectionClass($attribute))->getShortName();
        $cascade = array_map('strtolower', (array) ($attribute->cascade ?? []));
        $association = [
            'type' => $type,
            'field' => $field,
            'target' => $target,
            'mappedBy' => $attribute->mappedBy ?? null,
            'inversedBy' => $attribute->inversedBy ?? null,
            'owning' => false,
            'joinColumn' => null,
            'referencedColumn' => null,
            'joinTable' => null,
            'inverseJoinColumn' => null,
            'nullable' => $attribute->nullable ?? true,
            'onDelete' => isset($attribute->onDelete) ? strtoupper((string) $attribute->onDelete) : null,
            'cascade' => [
                'persist' => in_array('persist', $cascade, true) || in_array('all', $cascade, true),
                'remove' => in_array('remove', $cascade, true) || in_array('all', $cascade, true),
            ],
            'orphanRemoval' => $attribute->orphanRemoval ?? false,
            'orderBy' => $attribute->orderBy ?? [],
            'fetch' => $attribute->fetch ?? 'lazy',
            'resolved' => false,
        ];

        switch ($type) {
            case ClassMetadata::MANY_TO_ONE:
                $association['owning'] = true;
                $association['joinColumn'] = $attribute->joinColumn;
                break;
            case ClassMetadata::ONE_TO_ONE:
                $association['owning'] = $attribute->mappedBy === null;
                $association['joinColumn'] = $attribute->joinColumn;
                break;
            case ClassMetadata::MANY_TO_MANY:
                $association['owning'] = $attribute->mappedBy === null;
                $association['joinTable'] = $attribute->joinTable;
                $association['joinColumn'] = $attribute->joinColumn;
                $association['inverseJoinColumn'] = $attribute->inverseJoinColumn;
                break;
        }

        return $association;
    }

    protected function resolveAssociation(ClassMetadata $metadata, array $association): array
    {
        if ($association['resolved']) {
            return $association;
        }

        $association['resolved'] = true;
        $target = $this->getMetadata($association['target']);
        $association['target'] = $target->name;
        $field = $association['field'];

        if (in_array($association['type'], ClassMetadata::TO_ONE, true) && $association['owning']) {
            $association['referencedColumn'] = $target->getIdentifierColumn();
            $association['joinColumn'] ??= $this->naming->joinColumnName($field, $association['referencedColumn']);

            if (isset($metadata->columns[$association['joinColumn']])) {
                throw new MappingException('The join column "{column}" of "{class}::${field}" is already mapped to a field.', 0, null, ['class' => $metadata->name, 'field' => $field, 'column' => $association['joinColumn']]);
            }

            return $association;
        }

        if ($association['type'] === ClassMetadata::MANY_TO_MANY && $association['owning']) {
            $same = $metadata->name === $target->name;
            $association['joinTable'] ??= $same ? $metadata->table . '_' . $this->naming->toSnakeCase($field) : $this->naming->joinTableName($metadata->name, $target->name);
            $association['joinColumn'] ??= $same ? $metadata->table . '_source' : $this->naming->joinKeyColumnName($metadata->name, $metadata->getIdentifierColumn());
            $association['inverseJoinColumn'] ??= $same ? $metadata->table . '_target' : $this->naming->joinKeyColumnName($target->name, $target->getIdentifierColumn());
            $association['referencedColumn'] = $target->getIdentifierColumn();

            return $association;
        }

        $mappedBy = (string) $association['mappedBy'];

        if (!$target->hasAssociation($mappedBy)) {
            throw new MappingException('The association "{class}::${field}" is mapped by "{target}::${mappedBy}", which does not exist.', 0, null, [
                'class' => $metadata->name,
                'field' => $field,
                'target' => $target->name,
                'mappedBy' => $mappedBy,
            ]);
        }

        $owning = $target->associations[$mappedBy];

        if (!$owning['resolved'] && $owning['owning']) {
            $owning = $this->resolveAssociation($target, $owning);
            $target->associations[$mappedBy] = $owning;
        }

        if ($association['type'] === ClassMetadata::MANY_TO_MANY) {
            $association['joinTable'] = $owning['joinTable'];
            $association['joinColumn'] = $owning['inverseJoinColumn'];
            $association['inverseJoinColumn'] = $owning['joinColumn'];
            $association['referencedColumn'] = $target->getIdentifierColumn();

            return $association;
        }

        if (!in_array($owning['type'], ClassMetadata::TO_ONE, true) || !$owning['owning']) {
            throw new MappingException('The association "{class}::${field}" must be mapped by an owning ManyToOne or OneToOne association.', 0, null, ['class' => $metadata->name, 'field' => $field]);
        }

        $association['joinColumn'] = $owning['joinColumn'];
        $association['referencedColumn'] = $metadata->getIdentifierColumn();

        return $association;
    }
}