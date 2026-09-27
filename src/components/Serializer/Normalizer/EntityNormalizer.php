<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Normalizer;

use Closure;
use NeoPHP\Component\Serializer\Contract\AbstractSerializer;
use NeoPHP\Component\Serializer\Contract\NameConverterInterface;
use NeoPHP\Component\Serializer\Exception\NotNormalizableValueException;
use NeoPHP\Component\Serializer\Mapping\ClassMetadata;
use NeoPHP\Component\Serializer\Mapping\ClassResolver;
use NeoPHP\Component\Serializer\Mapping\MetadataFactory;
use NeoPHP\Component\Serializer\Mapping\PropertyMetadata;
use NeoPHP\Package\Orm\Collection\ArrayCollection;
use NeoPHP\Package\Orm\Contract\CollectionInterface;
use NeoPHP\Package\Orm\Contract\OrmInterface;
use NeoPHP\Package\Orm\Contract\ProxyInterface;
use NeoPHP\Package\Orm\Mapping\Entity;
use NeoPHP\Package\Orm\Metadata\ClassMetadata as OrmClassMetadata;
use ReflectionClass;
use ReflectionType;
use Traversable;

class EntityNormalizer extends ObjectNormalizer
{
    protected ?OrmInterface $orm = null;

    protected array $entities = [];

    public function __construct(protected Closure $ormFactory, MetadataFactory $metadataFactory = new MetadataFactory(), ?NameConverterInterface $nameConverter = null)
    {
        parent::__construct($metadataFactory, $nameConverter);
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return is_object($data) && $this->isEntity($data);
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $this->isEntity($type);
    }

    public function isEntity(string|object $class): bool
    {
        $class = ClassResolver::getRealClass($class);

        return $this->entities[$class] ??= class_exists($class) && (new ReflectionClass($class))->getAttributes(Entity::class) !== [];
    }

    public function getIdentifier(object $entity): mixed
    {
        return $this->ormMetadata($entity)->getIdentifierValue($entity);
    }

    protected function orm(): OrmInterface
    {
        return $this->orm ??= ($this->ormFactory)();
    }

    protected function ormMetadata(string|object $class): OrmClassMetadata
    {
        return $this->orm()->getMetadata(ClassResolver::getRealClass($class));
    }

    protected function association(string $class, string $field): ?array
    {
        $metadata = $this->ormMetadata($class);

        return $metadata->hasAssociation($field) ? $metadata->getAssociation($field) : null;
    }

    protected function prepareNormalization(object $object): void
    {
        if ($object instanceof ProxyInterface && !$object->__neoIsInitialized()) {
            $object->__neoLoad();
        }
    }

    protected function normalizeAttribute(object $object, PropertyMetadata $property, mixed $value, ?string $format, array $context): mixed
    {
        $groups = self::groups($context);

        if (is_object($value) && $this->isEntity($value)) {
            $maxDepth = (int) ($context[AbstractSerializer::MAX_DEPTH] ?? 0);

            if (($maxDepth > 0 && (int) ($context[AbstractSerializer::DEPTH] ?? 0) >= $maxDepth) || ($groups !== null && !$this->hasAttributesInGroups($value, $groups))) {
                return $this->getIdentifier($value);
            }

            return parent::normalizeAttribute($object, $property, $value, $format, $context);
        }

        $association = $this->association(ClassResolver::getRealClass($object), $property->name);

        if ($association !== null && $value instanceof Traversable && $groups !== null && !$this->hasAttributesInGroups($association['target'], $groups)) {
            return $this->identifiers($value);
        }

        return parent::normalizeAttribute($object, $property, $value, $format, $context);
    }

    protected function handleAttributeMaxDepth(object $object, PropertyMetadata $property, mixed $value, ?string $format, array $context): array
    {
        if (is_object($value) && $this->isEntity($value)) {
            return [true, $this->getIdentifier($value)];
        }

        if ($value instanceof Traversable && $this->association(ClassResolver::getRealClass($object), $property->name) !== null) {
            return [true, $this->identifiers($value)];
        }

        return parent::handleAttributeMaxDepth($object, $property, $value, $format, $context);
    }

    protected function handleCircularReference(object $object, ?string $format, array $context): mixed
    {
        if (is_callable($context[AbstractSerializer::CIRCULAR_REFERENCE_HANDLER] ?? null)) {
            return parent::handleCircularReference($object, $format, $context);
        }

        return $this->getIdentifier($object);
    }

    protected function handleMaxDepth(object $object, ?string $format, array $context): mixed
    {
        return $this->getIdentifier($object);
    }

    protected function isWritable(ClassMetadata $metadata, PropertyMetadata $property): bool
    {
        return $property->isWritable() || (!$property->ignored && $this->association($metadata->class, $property->name) !== null);
    }

    protected function writeValue(object $object, ClassMetadata $metadata, PropertyMetadata $property, mixed $value, ?string $format, array $context, ?array $groups): void
    {
        $association = $this->association($metadata->class, $property->name);

        if ($association === null) {
            parent::writeValue($object, $metadata, $property, $value, $format, $context, $groups);

            return;
        }

        $childContext = $this->childContext($property, $context, $groups, false);
        $ormMetadata = $this->ormMetadata($metadata->class);

        if (in_array($association['type'], OrmClassMetadata::TO_ONE, true)) {
            $entity = $this->resolveEntity($association['target'], $value, $format, $childContext);

            if ($entity === null && !($association['nullable'] ?? true)) {
                throw new NotNormalizableValueException('The value{at} must not be null.', 0, null, [
                    'at' => AbstractSerializer::describePath($childContext),
                    'path' => AbstractSerializer::path($childContext),
                ]);
            }

            if ($property->setter !== null && ($entity !== null || $property->setterType === null || $property->setterType->allowsNull())) {
                $object->{$property->setter}($entity);
            } else {
                $ormMetadata->setValue($object, $property->name, $entity);
            }

            return;
        }

        if ($value === null || $value === '') {
            $value = [];
        }

        if (!is_array($value) || !array_is_list($value)) {
            $value = AbstractSerializer::isLenient($format, $childContext) && is_array($value) ? [$value] : $value;
        }

        if (!is_array($value)) {
            throw new NotNormalizableValueException('The value{at} must be a list of identifiers or objects, {actual} given.', 0, null, [
                'actual' => get_debug_type($value),
                'at' => AbstractSerializer::describePath($childContext),
                'path' => AbstractSerializer::path($childContext),
            ]);
        }

        $entities = [];

        foreach (array_values($value) as $index => $item) {
            $entity = $this->resolveEntity($association['target'], $item, $format, AbstractSerializer::withPath($childContext, $index));

            if ($entity !== null) {
                $entities[] = $entity;
            }
        }

        $collection = $ormMetadata->getValue($object, $property->name);

        if (!$collection instanceof CollectionInterface) {
            $ormMetadata->setValue($object, $property->name, new ArrayCollection($entities));

            return;
        }

        foreach ($collection->toArray() as $current) {
            if (!in_array($current, $entities, true)) {
                $collection->removeElement($current);
            }
        }

        foreach ($entities as $entity) {
            if (!$collection->contains($entity)) {
                $collection->add($entity);
            }
        }
    }

    protected function denormalizeValue(ClassMetadata $metadata, PropertyMetadata $property, mixed $value, ?ReflectionType $type, ?string $format, array $context): mixed
    {
        $association = $this->association($metadata->class, $property->name);

        if ($association !== null && in_array($association['type'], OrmClassMetadata::TO_ONE, true)) {
            return $this->resolveEntity($association['target'], $value, $format, $context);
        }

        return parent::denormalizeValue($metadata, $property, $value, $type, $format, $context);
    }

    protected function resolveEntity(string $class, mixed $value, ?string $format, array $context): ?object
    {
        if ($value === null || ($value === '' && AbstractSerializer::isLenient($format, $context))) {
            return null;
        }

        if (is_object($value) && $value instanceof $class) {
            return $value;
        }

        $id = $value;

        if (is_array($value)) {
            $identifier = $this->ormMetadata($class)->getIdentifier();
            $property = $this->metadataFactory->getMetadata($class)->getProperty($identifier);
            $key = $property === null ? $identifier : $this->serializedName($property);

            if (!array_key_exists($key, $value) || $value[$key] === null || $value[$key] === '') {
                return $this->serializer()->denormalize($value, $class, $format, $context);
            }

            $id = $value[$key];
        }

        if (!is_int($id) && !is_string($id)) {
            throw new NotNormalizableValueException('The value{at} must be an identifier of {class}, {actual} given.', 0, null, [
                'class' => $class,
                'actual' => get_debug_type($id),
                'at' => AbstractSerializer::describePath($context),
                'path' => AbstractSerializer::path($context),
            ]);
        }

        $entity = $this->orm()->find($class, $id);

        if ($entity === null) {
            throw new NotNormalizableValueException('The {entity} "{id}"{at} does not exist.', 0, null, [
                'entity' => (new ReflectionClass($class))->getShortName(),
                'class' => $class,
                'id' => $id,
                'at' => AbstractSerializer::describePath($context),
                'path' => AbstractSerializer::path($context),
            ]);
        }

        return $entity;
    }

    protected function identifiers(iterable $entities): array
    {
        $ids = [];

        foreach ($entities as $entity) {
            $ids[] = is_object($entity) && $this->isEntity($entity) ? $this->getIdentifier($entity) : $entity;
        }

        return $ids;
    }

    protected function hasAttributesInGroups(string|object $class, array $groups): bool
    {
        if (in_array('*', $groups, true)) {
            return true;
        }

        foreach ($this->metadataFactory->getMetadata($class)->properties as $property) {
            if ($property->isReadable() && array_intersect($property->groups, $groups) !== []) {
                return true;
            }
        }

        return false;
    }
}