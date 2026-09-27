<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Normalizer;

use Closure;
use Error;
use NeoPHP\Component\Serializer\Contract\AbstractSerializer;
use NeoPHP\Component\Serializer\Contract\DenormalizerInterface;
use NeoPHP\Component\Serializer\Contract\NameConverterInterface;
use NeoPHP\Component\Serializer\Contract\NormalizerInterface;
use NeoPHP\Component\Serializer\Contract\SerializerAwareInterface;
use NeoPHP\Component\Serializer\Exception\CircularReferenceException;
use NeoPHP\Component\Serializer\Exception\ExtraAttributesException;
use NeoPHP\Component\Serializer\Exception\MissingConstructorArgumentsException;
use NeoPHP\Component\Serializer\Exception\NotNormalizableValueException;
use NeoPHP\Component\Serializer\Mapping\ClassMetadata;
use NeoPHP\Component\Serializer\Mapping\ClassResolver;
use NeoPHP\Component\Serializer\Mapping\MetadataFactory;
use NeoPHP\Component\Serializer\Mapping\PropertyMetadata;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use stdClass;
use Throwable;
use Traversable;
use TypeError;
use UnitEnum;

class ObjectNormalizer implements NormalizerInterface, DenormalizerInterface, SerializerAwareInterface
{
    use SerializerAwareTrait;

    public function __construct(protected MetadataFactory $metadataFactory = new MetadataFactory(), protected ?NameConverterInterface $nameConverter = null)
    {
    }

    public function getMetadataFactory(): MetadataFactory
    {
        return $this->metadataFactory;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return is_object($data) && !$data instanceof Traversable && !$data instanceof stdClass && !$data instanceof Closure && !$data instanceof UnitEnum;
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return class_exists($type) && !enum_exists($type);
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): mixed
    {
        if ($this->isCircularReference($data, $context)) {
            return $this->handleCircularReference($data, $format, $context);
        }

        $depth = (int) ($context[AbstractSerializer::DEPTH] ?? 0);
        $maxDepth = (int) ($context[AbstractSerializer::MAX_DEPTH] ?? 0);

        if ($maxDepth > 0 && $depth >= $maxDepth) {
            return $this->handleMaxDepth($data, $format, $context);
        }

        $id = spl_object_id($data);
        $context[AbstractSerializer::CIRCULAR_COUNTERS][$id] = ($context[AbstractSerializer::CIRCULAR_COUNTERS][$id] ?? 0) + 1;
        $context[AbstractSerializer::DEPTH] = $depth + 1;
        unset($context[AbstractSerializer::OBJECT_TO_POPULATE]);

        $this->prepareNormalization($data);
        $metadata = $this->metadataFactory->getMetadata($data);
        $groups = self::groups($context);
        $result = [];

        foreach ($metadata->properties as $name => $property) {
            if (!$property->isReadable() || !$this->isAllowed($property, $context, $groups)) {
                continue;
            }

            $childContext = $this->childContext($property, $context, $groups, true);
            $limited = false;

            if ($property->maxDepth !== null && ($context[AbstractSerializer::ENABLE_MAX_DEPTH] ?? false)) {
                $key = $metadata->class . '::' . $name;
                $count = (int) ($context[AbstractSerializer::MAX_DEPTH_COUNTERS][$key] ?? 0) + 1;
                $childContext[AbstractSerializer::MAX_DEPTH_COUNTERS][$key] = $count;
                $limited = $count > $property->maxDepth;
            }

            $value = $this->readValue($data, $property);

            if ($limited) {
                [$keep, $value] = $this->handleAttributeMaxDepth($data, $property, $value, $format, $childContext);

                if (!$keep) {
                    continue;
                }
            } else {
                $value = $value === null || is_scalar($value) ? $value : $this->normalizeAttribute($data, $property, $value, $format, $childContext);
            }

            if ($value === null && ($context[AbstractSerializer::SKIP_NULL_VALUES] ?? false)) {
                continue;
            }

            $result[$this->serializedName($property)] = $value;
        }

        return $result;
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        if (is_object($data) && $data instanceof $type) {
            return $data;
        }

        if ($data instanceof stdClass) {
            $data = (array) $data;
        }

        if (!is_array($data) || ($data !== [] && array_is_list($data) && !$this->acceptsList($type))) {
            throw new NotNormalizableValueException('The value{at} must be an object ({type}), {actual} given.', 0, null, [
                'type' => $type,
                'actual' => is_array($data) ? 'a list' : get_debug_type($data),
                'at' => AbstractSerializer::describePath($context),
                'path' => AbstractSerializer::path($context),
            ]);
        }

        $metadata = $this->metadataFactory->getMetadata($type);
        $groups = self::groups($context);
        $map = [];

        foreach ($metadata->properties as $name => $property) {
            if ($this->isWritable($metadata, $property) && $this->isAllowed($property, $context, $groups)) {
                $map[$this->serializedName($property)] = $property;
            }
        }

        $values = [];
        $extra = [];

        foreach ($data as $key => $value) {
            $property = $map[(string) $key] ?? null;

            if ($property === null) {
                $extra[] = (string) $key;
                continue;
            }

            $values[$property->name] = $value;
        }

        if ($extra !== [] && ($context[AbstractSerializer::ALLOW_EXTRA_ATTRIBUTES] ?? true) === false) {
            throw new ExtraAttributesException('Extra attributes are not allowed{at}: {attributes} (class {class}).', 0, null, [
                'attributes' => '"' . implode('", "', $extra) . '"',
                'extra' => $extra,
                'class' => $metadata->class,
                'at' => AbstractSerializer::describePath($context),
                'path' => AbstractSerializer::path($context),
            ]);
        }

        $populate = $context[AbstractSerializer::OBJECT_TO_POPULATE] ?? null;
        unset($context[AbstractSerializer::OBJECT_TO_POPULATE]);

        if (is_object($populate) && $populate instanceof $type) {
            $object = $populate;
        } else {
            $object = $this->instantiate($metadata, $values, $format, $context, $groups);
        }

        foreach ($values as $name => $value) {
            $this->writeValue($object, $metadata, $metadata->properties[$name], $value, $format, $context, $groups);
        }

        return $object;
    }

    public static function groups(array $context): ?array
    {
        $groups = $context[AbstractSerializer::GROUPS] ?? null;

        if ($groups === null || $groups === [] || $groups === '') {
            return null;
        }

        return array_values(array_map('strval', (array) $groups));
    }

    public function serializedName(PropertyMetadata $property): string
    {
        return $property->serializedName ?? ($this->nameConverter === null ? $property->name : $this->nameConverter->normalize($property->name));
    }

    protected function acceptsList(string $type): bool
    {
        return false;
    }

    protected function isWritable(ClassMetadata $metadata, PropertyMetadata $property): bool
    {
        return $property->isWritable();
    }

    protected function isAllowed(PropertyMetadata $property, array $context, ?array $groups): bool
    {
        if ($property->ignored || in_array($property->name, (array) ($context[AbstractSerializer::IGNORED_ATTRIBUTES] ?? []), true)) {
            return false;
        }

        $attributes = $context[AbstractSerializer::ATTRIBUTES] ?? null;

        if (is_array($attributes) && !in_array($property->name, $attributes, true) && !array_key_exists($property->name, $attributes)) {
            return false;
        }

        if ($groups === null) {
            return true;
        }

        return in_array('*', $groups, true) || array_intersect($property->groups, $groups) !== [];
    }

    protected function childContext(PropertyMetadata $property, array $context, ?array $groups, bool $normalization): array
    {
        $attributes = $context[AbstractSerializer::ATTRIBUTES] ?? null;

        if (is_array($attributes) && isset($attributes[$property->name]) && is_array($attributes[$property->name])) {
            $context[AbstractSerializer::ATTRIBUTES] = $attributes[$property->name];
        } else {
            unset($context[AbstractSerializer::ATTRIBUTES]);
        }

        $context = array_replace($context, $property->getContext($normalization, $groups ?? []));

        return AbstractSerializer::withPath($context, $this->serializedName($property));
    }

    protected function prepareNormalization(object $object): void
    {
    }

    protected function readValue(object $object, PropertyMetadata $property): mixed
    {
        try {
            if ($property->getter !== null) {
                return $object->{$property->getter}();
            }

            $reflection = new ReflectionProperty($object, $property->name);

            return $reflection->isInitialized($object) ? $reflection->getValue($object) : null;
        } catch (Error $error) {
            if ($error instanceof TypeError) {
                throw $error;
            }

            return null;
        }
    }

    protected function normalizeAttribute(object $object, PropertyMetadata $property, mixed $value, ?string $format, array $context): mixed
    {
        return $this->serializer()->normalize($value, $format, $context);
    }

    protected function handleAttributeMaxDepth(object $object, PropertyMetadata $property, mixed $value, ?string $format, array $context): array
    {
        return [false, null];
    }

    protected function isCircularReference(object $object, array $context): bool
    {
        $limit = max(1, (int) ($context[AbstractSerializer::CIRCULAR_REFERENCE_LIMIT] ?? 1));

        return ($context[AbstractSerializer::CIRCULAR_COUNTERS][spl_object_id($object)] ?? 0) >= $limit;
    }

    protected function handleCircularReference(object $object, ?string $format, array $context): mixed
    {
        $handler = $context[AbstractSerializer::CIRCULAR_REFERENCE_HANDLER] ?? null;

        if (is_callable($handler)) {
            return $handler($object, $format, $context);
        }

        throw new CircularReferenceException('A circular reference has been detected when serializing an object of class "{class}"{at} (limit: {limit}). Use serialization groups, #[Ignore], #[MaxDepth], the "ignored_attributes" option or a "circular_reference_handler".', 0, null, [
            'class' => ClassResolver::getRealClass($object),
            'limit' => (int) ($context[AbstractSerializer::CIRCULAR_REFERENCE_LIMIT] ?? 1),
            'at' => AbstractSerializer::describePath($context),
            'path' => AbstractSerializer::path($context),
        ]);
    }

    protected function handleMaxDepth(object $object, ?string $format, array $context): mixed
    {
        throw new NotNormalizableValueException('The maximum depth of {depth} has been reached when serializing an object of class "{class}"{at}: raise "max_depth" or use serialization groups.', 0, null, [
            'depth' => (int) ($context[AbstractSerializer::MAX_DEPTH] ?? 0),
            'class' => ClassResolver::getRealClass($object),
            'at' => AbstractSerializer::describePath($context),
            'path' => AbstractSerializer::path($context),
        ]);
    }

    protected function instantiate(ClassMetadata $metadata, array &$values, ?string $format, array $context, ?array $groups): object
    {
        $reflection = $metadata->reflection;

        if (!$reflection->isInstantiable()) {
            throw new NotNormalizableValueException('Unable to create an instance of "{class}"{at}: the class is abstract or not instantiable.', 0, null, [
                'class' => $metadata->class,
                'at' => AbstractSerializer::describePath($context),
                'path' => AbstractSerializer::path($context),
            ]);
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];
        $missing = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            $property = $metadata->properties[$name] ?? null;

            if ($property !== null && array_key_exists($name, $values)) {
                $arguments[$name] = $this->denormalizeValue($metadata, $property, $values[$name], $parameter->getType(), $format, $this->childContext($property, $context, $groups, false));
                unset($values[$name]);
                continue;
            }

            if ($parameter->isVariadic()) {
                break;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[$name] = $parameter->getDefaultValue();
                continue;
            }

            if ($parameter->allowsNull()) {
                $arguments[$name] = null;
                continue;
            }

            $missing[] = $this->missingName($metadata, $parameter);
        }

        if ($missing !== []) {
            throw new MissingConstructorArgumentsException('Unable to create an instance of "{class}"{at}: the constructor requires the missing attribute(s) {arguments}.', 0, null, [
                'class' => $metadata->class,
                'arguments' => '"' . implode('", "', $missing) . '"',
                'missing' => array_map(static fn (string $name): string => AbstractSerializer::path(AbstractSerializer::withPath($context, $name)), $missing),
                'at' => AbstractSerializer::describePath($context),
                'path' => AbstractSerializer::path($context),
            ]);
        }

        try {
            return $reflection->newInstanceArgs($arguments);
        } catch (TypeError $error) {
            throw new NotNormalizableValueException('Unable to create an instance of "{class}"{at}: {error}', 0, $error, [
                'class' => $metadata->class,
                'error' => $error->getMessage(),
                'at' => AbstractSerializer::describePath($context),
                'path' => AbstractSerializer::path($context),
            ]);
        }
    }

    protected function missingName(ClassMetadata $metadata, ReflectionParameter $parameter): string
    {
        $property = $metadata->properties[$parameter->getName()] ?? null;

        return $property === null ? $parameter->getName() : $this->serializedName($property);
    }

    protected function writeValue(object $object, ClassMetadata $metadata, PropertyMetadata $property, mixed $value, ?string $format, array $context, ?array $groups): void
    {
        if ($property->setter === null && !$property->publicWrite) {
            return;
        }

        $childContext = $this->childContext($property, $context, $groups, false);
        $value = $this->denormalizeValue($metadata, $property, $value, $property->getWriteType(), $format, $childContext);

        try {
            if ($property->setter !== null) {
                $object->{$property->setter}($value);
            } else {
                $object->{$property->name} = $value;
            }
        } catch (TypeError $error) {
            throw new NotNormalizableValueException('The value{at} has an invalid type for {class}::${property}: {error}', 0, $error, [
                'class' => $metadata->class,
                'property' => $property->name,
                'error' => $error->getMessage(),
                'at' => AbstractSerializer::describePath($childContext),
                'path' => AbstractSerializer::path($childContext),
            ]);
        }
    }

    protected function denormalizeValue(ClassMetadata $metadata, PropertyMetadata $property, mixed $value, ?ReflectionType $type, ?string $format, array $context): mixed
    {
        if ($property->type !== null) {
            if ($value === null && ($type === null || $type->allowsNull())) {
                return null;
            }

            if ($value === '' && $type !== null && $type->allowsNull() && AbstractSerializer::isLenient($format, $context)) {
                return null;
            }

            return $this->serializer()->denormalize($value, $property->type, $format, $context);
        }

        return $this->denormalizeType($value, $type, $metadata->class, $format, $context);
    }

    protected function denormalizeType(mixed $value, ?ReflectionType $type, string $class, ?string $format, array $context): mixed
    {
        if ($type === null) {
            return $value;
        }

        $names = $type instanceof ReflectionUnionType ? array_map('strval', $type->getTypes()) : ($type instanceof ReflectionNamedType ? [$type->getName()] : []);
        $names = array_values(array_filter(array_map(static fn (string $name): string => in_array($name, ['self', 'static'], true) ? $class : $name, $names), static fn (string $name): bool => $name !== 'null'));

        if ($names === []) {
            return $value;
        }

        if ($value === '' && $type->allowsNull() && !in_array('string', $names, true) && !in_array('mixed', $names, true) && AbstractSerializer::isLenient($format, $context)) {
            return null;
        }

        if ($value === null) {
            if ($type->allowsNull()) {
                return null;
            }

            throw new NotNormalizableValueException('The value{at} must not be null (expected {type}).', 0, null, [
                'type' => (string) $type,
                'at' => AbstractSerializer::describePath($context),
                'path' => AbstractSerializer::path($context),
            ]);
        }

        if (count($names) === 1) {
            return $this->serializer()->denormalize($value, $names[0], $format, $context);
        }

        foreach ($names as $name) {
            if ($this->matches($value, $name)) {
                return $value;
            }
        }

        $last = null;

        foreach ($names as $name) {
            try {
                return $this->serializer()->denormalize($value, $name, $format, $context);
            } catch (NotNormalizableValueException $exception) {
                $last = $exception;
            } catch (Throwable $exception) {
                $last ??= $exception;
            }
        }

        throw new NotNormalizableValueException('The value{at} must be of type {type}, {actual} given.', 0, $last, [
            'type' => (string) $type,
            'actual' => get_debug_type($value),
            'at' => AbstractSerializer::describePath($context),
            'path' => AbstractSerializer::path($context),
        ]);
    }

    protected function matches(mixed $value, string $type): bool
    {
        return match (strtolower($type)) {
            'mixed' => true,
            'int' => is_int($value),
            'float' => is_float($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            'true' => $value === true,
            'false' => $value === false,
            'array', 'iterable' => is_array($value),
            default => is_object($value) && $value instanceof $type,
        };
    }
}