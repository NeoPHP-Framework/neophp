<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\OpenApi;

use BackedEnum;
use DateTimeInterface;
use NeoPHP\Component\Api\OpenApi\Attribute\Property;
use NeoPHP\Component\Api\OpenApi\Attribute\Schema;
use NeoPHP\Component\Serializer\Contract\NameConverterInterface;
use NeoPHP\Component\Serializer\Mapping\MetadataFactory;
use NeoPHP\Component\Serializer\Mapping\PropertyMetadata;
use NeoPHP\Component\Validator\Constraint\Choice;
use NeoPHP\Component\Validator\Constraint\Count;
use NeoPHP\Component\Validator\Constraint\Date;
use NeoPHP\Component\Validator\Constraint\DateTime;
use NeoPHP\Component\Validator\Constraint\Email;
use NeoPHP\Component\Validator\Constraint\GreaterThan;
use NeoPHP\Component\Validator\Constraint\GreaterThanOrEqual;
use NeoPHP\Component\Validator\Constraint\Ip;
use NeoPHP\Component\Validator\Constraint\Length;
use NeoPHP\Component\Validator\Constraint\LessThan;
use NeoPHP\Component\Validator\Constraint\LessThanOrEqual;
use NeoPHP\Component\Validator\Constraint\Negative;
use NeoPHP\Component\Validator\Constraint\NegativeOrZero;
use NeoPHP\Component\Validator\Constraint\NotBlank;
use NeoPHP\Component\Validator\Constraint\NotNull;
use NeoPHP\Component\Validator\Constraint\Positive;
use NeoPHP\Component\Validator\Constraint\PositiveOrZero;
use NeoPHP\Component\Validator\Constraint\Range;
use NeoPHP\Component\Validator\Constraint\Regex;
use NeoPHP\Component\Validator\Constraint\Url;
use NeoPHP\Component\Validator\Constraint\Uuid;
use NeoPHP\Component\Validator\Contract\AbstractConstraint;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Throwable;
use Traversable;
use UnitEnum;

class SchemaGenerator
{
    public const READ = 'read';

    public const WRITE = 'write';

    public const REF_PREFIX = '#/components/schemas/';

    protected array $schemas = [];

    protected array $names = [];

    public function __construct(protected MetadataFactory $metadata = new MetadataFactory(), protected ?NameConverterInterface $nameConverter = null)
    {
    }

    public function getSchemas(): array
    {
        $schemas = $this->schemas;
        ksort($schemas);

        return $schemas;
    }

    public function addSchema(string $name, array $schema): array
    {
        $this->schemas[$name] = $schema;

        return ['$ref' => self::REF_PREFIX . $name];
    }

    public function hasSchema(string $name): bool
    {
        return isset($this->schemas[$name]);
    }

    public function type(string $type, ?array $groups = null, string $direction = self::READ, ?array $validationGroups = null): array
    {
        $type = trim($type);

        if (str_starts_with($type, '?')) {
            return $this->nullable($this->type(substr($type, 1), $groups, $direction, $validationGroups));
        }

        $parts = $this->splitUnion($type);

        if (count($parts) > 1) {
            $nullable = in_array('null', array_map('strtolower', $parts), true);
            $schemas = [];

            foreach ($parts as $part) {
                if (strtolower($part) !== 'null') {
                    $schemas[] = $this->type($part, $groups, $direction, $validationGroups);
                }
            }

            $schema = count($schemas) === 1 ? $schemas[0] : ['oneOf' => $schemas];

            return $nullable ? $this->nullable($schema) : $schema;
        }

        if (str_ends_with($type, '[]')) {
            return ['type' => 'array', 'items' => $this->type(substr($type, 0, -2), $groups, $direction, $validationGroups)];
        }

        if (preg_match('/^(?:array|list|iterable)<(?:\s*(int|string)\s*,)?\s*(.+)>$/i', $type, $matches) === 1) {
            $items = $this->type($matches[2], $groups, $direction, $validationGroups);

            return strtolower($matches[1]) === 'string' ? ['type' => 'object', 'additionalProperties' => $items] : ['type' => 'array', 'items' => $items];
        }

        return match (strtolower(ltrim($type, '\\'))) {
            'int', 'integer' => ['type' => 'integer'],
            'float', 'double' => ['type' => 'number'],
            'string' => ['type' => 'string'],
            'bool', 'boolean', 'true', 'false' => ['type' => 'boolean'],
            'array', 'iterable' => ['type' => 'array', 'items' => (object) []],
            'object', 'stdclass' => ['type' => 'object'],
            'null', 'void' => ['type' => 'null'],
            'mixed', '' => (object) [],
            default => $this->classSchema(ltrim($type, '\\'), $groups, $direction, $validationGroups),
        };
    }

    public function reflectionType(?ReflectionType $type, ?array $groups = null, string $direction = self::READ, ?array $validationGroups = null): array|object
    {
        if ($type === null) {
            return (object) [];
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            $schema = in_array($name, ['self', 'static'], true) ? (object) [] : $this->type($name, $groups, $direction, $validationGroups);

            return $type->allowsNull() && $name !== 'null' && $name !== 'mixed' ? $this->nullable($schema) : $schema;
        }

        if ($type instanceof ReflectionUnionType) {
            return $this->type(implode('|', array_map(static fn (ReflectionType $item): string => (string) $item, $type->getTypes())), $groups, $direction, $validationGroups);
        }

        return (object) [];
    }

    public function classSchema(string $class, ?array $groups = null, string $direction = self::READ, ?array $validationGroups = null): array
    {
        if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) {
            return ['type' => 'object'];
        }

        if (is_a($class, DateTimeInterface::class, true)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        if (is_a($class, UnitEnum::class, true)) {
            return $this->enumSchema($class);
        }

        if (is_a($class, Traversable::class, true) || (new ReflectionClass($class))->isInterface()) {
            return ['type' => 'array', 'items' => (object) []];
        }

        $key = $class . '|' . $direction . '|' . implode(',', $groups ?? ['*']);

        if (isset($this->names[$key])) {
            return ['$ref' => self::REF_PREFIX . $this->names[$key]];
        }

        $name = $this->name($class, $groups, $direction);
        $this->names[$key] = $name;
        $this->schemas[$name] = [];
        $this->schemas[$name] = $this->objectSchema($class, $groups, $direction, $validationGroups);

        return ['$ref' => self::REF_PREFIX . $name];
    }

    public function objectSchema(string $class, ?array $groups = null, string $direction = self::READ, ?array $validationGroups = null): array
    {
        [$properties, $required] = $this->properties($class, $groups, $direction, $validationGroups);
        $schema = ['type' => 'object'];
        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(Schema::class);

        if ($attributes !== []) {
            $attribute = $attributes[0]->newInstance();

            if ($attribute->description !== null) {
                $schema['description'] = $attribute->description;
            }

            if ($attribute->example !== null) {
                $schema['example'] = $attribute->example;
            }
        }

        if ($required !== []) {
            $schema['required'] = $required;
        }

        $schema['properties'] = $properties === [] ? (object) [] : $properties;

        return $schema;
    }

    public function properties(string $class, ?array $groups = null, string $direction = self::READ, ?array $validationGroups = null): array
    {
        $metadata = $this->metadata->getMetadata($class);
        $reflection = $metadata->reflection;
        $properties = [];
        $required = [];

        foreach ($metadata->getProperties() as $property) {
            if (!$this->isIncluded($property, $groups, $direction)) {
                continue;
            }

            $name = $property->serializedName ?? ($this->nameConverter === null ? $property->name : $this->nameConverter->normalize($property->name));
            $reflectionProperty = $reflection->hasProperty($property->name) ? $reflection->getProperty($property->name) : null;
            $schema = $this->propertySchema($reflection, $property, $reflectionProperty, $groups, $direction, $validationGroups);
            $constraints = $this->constraints($reflectionProperty, $validationGroups);
            $isRequired = $this->isRequired($reflection, $property, $reflectionProperty, $constraints, $direction);
            [$schema, $isRequired] = $this->applyProperty($this->applyConstraints($schema, $constraints), $reflectionProperty, $isRequired);
            $properties[$name] = $schema;

            if ($isRequired) {
                $required[] = $name;
            }
        }

        return [$properties, $required];
    }

    public function nullable(array|object $schema): array
    {
        $schema = (array) $schema;

        if ($schema === []) {
            return $schema;
        }

        if (isset($schema['$ref']) || isset($schema['oneOf'])) {
            $options = isset($schema['oneOf']) ? $schema['oneOf'] : [$schema];

            foreach ($options as $option) {
                if (($option['type'] ?? null) === 'null') {
                    return $schema;
                }
            }

            return ['oneOf' => [...$options, ['type' => 'null']]];
        }

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];

            if (!in_array('null', $types, true)) {
                $types[] = 'null';
            }

            $schema['type'] = $types;

            if (isset($schema['enum']) && !in_array(null, $schema['enum'], true)) {
                $schema['enum'][] = null;
            }
        }

        return $schema;
    }

    protected function enumSchema(string $class): array
    {
        $name = $this->shortName($class);
        $key = $class . '|enum';

        if (!isset($this->names[$key])) {
            $name = $this->unique($name);
            $this->names[$key] = $name;
            $reflection = new ReflectionEnum($class);
            $backing = $reflection->getBackingType();
            $cases = array_map(
                static fn (UnitEnum $case): int|string => $case instanceof BackedEnum ? $case->value : $case->name,
                $class::cases(),
            );
            $this->schemas[$name] = [
                'type' => $backing !== null && (string) $backing === 'int' ? 'integer' : 'string',
                'enum' => $cases,
            ];
        }

        return ['$ref' => self::REF_PREFIX . $this->names[$key]];
    }

    protected function propertySchema(ReflectionClass $class, PropertyMetadata $property, ?ReflectionProperty $reflection, ?array $groups, string $direction, ?array $validationGroups): array|object
    {
        if ($property->type !== null) {
            return $this->type($property->type, $groups, $direction, $validationGroups);
        }

        $type = $direction === self::WRITE ? $property->getWriteType() : ($property->propertyType ?? null);

        if ($type === null && $direction === self::READ && $property->getter !== null && $class->hasMethod($property->getter)) {
            $type = $class->getMethod($property->getter)->getReturnType();
        }

        return $this->reflectionType($type ?? $property->setterType, $groups, $direction, $validationGroups);
    }

    protected function isIncluded(PropertyMetadata $property, ?array $groups, string $direction): bool
    {
        if ($property->ignored || !($direction === self::WRITE ? $property->isWritable() : $property->isReadable())) {
            return false;
        }

        return $groups === null || in_array('*', $groups, true) || array_intersect($property->groups, $groups) !== [];
    }

    protected function constraints(?ReflectionProperty $property, ?array $validationGroups): array
    {
        if ($property === null) {
            return [];
        }

        $constraints = [];

        foreach ($property->getAttributes(AbstractConstraint::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            try {
                $constraint = $attribute->newInstance();
            } catch (Throwable) {
                continue;
            }

            if ($constraint->inGroups($validationGroups ?? [AbstractConstraint::DEFAULT_GROUP])) {
                $constraints[] = $constraint;
            }
        }

        return $constraints;
    }

    protected function isRequired(ReflectionClass $class, PropertyMetadata $property, ?ReflectionProperty $reflection, array $constraints, string $direction): bool
    {
        foreach ($constraints as $constraint) {
            if (($constraint instanceof NotBlank && !$constraint->allowNull) || $constraint instanceof NotNull) {
                return true;
            }
        }

        if ($direction === self::READ) {
            $type = $reflection?->getType();

            return $type !== null && !$type->allowsNull();
        }

        if (!$property->constructorArgument) {
            return false;
        }

        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() === $property->name) {
                return !$parameter->isOptional() && !$parameter->allowsNull();
            }
        }

        return false;
    }

    protected function applyConstraints(array|object $schema, array $constraints): array|object
    {
        if ($constraints === []) {
            return $schema;
        }

        $schema = (array) $schema;

        if ($schema === [] || isset($schema['$ref']) || isset($schema['oneOf'])) {
            return $schema === [] ? (object) [] : $schema;
        }

        $types = (array) ($schema['type'] ?? []);

        foreach ($constraints as $constraint) {
            if ($constraint instanceof Choice && $constraint->multiple && in_array('array', $types, true)) {
                $schema['items'] = ['enum' => array_values($constraint->choices)];
                continue;
            }

            $values = match (true) {
                $constraint instanceof NotBlank => in_array('string', $types, true) && !isset($schema['minLength']) ? ['minLength' => 1] : (in_array('array', $types, true) && !isset($schema['minItems']) ? ['minItems' => 1] : []),
                $constraint instanceof Length => array_filter(['minLength' => $constraint->min, 'maxLength' => $constraint->max], static fn (mixed $value): bool => $value !== null),
                $constraint instanceof Count => array_filter(['minItems' => $constraint->min, 'maxItems' => $constraint->max], static fn (mixed $value): bool => $value !== null),
                $constraint instanceof Range => array_filter(['minimum' => is_numeric($constraint->min) ? $constraint->min + 0 : null, 'maximum' => is_numeric($constraint->max) ? $constraint->max + 0 : null], static fn (mixed $value): bool => $value !== null),
                $constraint instanceof Positive => ['exclusiveMinimum' => 0],
                $constraint instanceof PositiveOrZero => ['minimum' => 0],
                $constraint instanceof Negative => ['exclusiveMaximum' => 0],
                $constraint instanceof NegativeOrZero => ['maximum' => 0],
                $constraint instanceof GreaterThan => is_numeric($constraint->value) ? ['exclusiveMinimum' => $constraint->value + 0] : [],
                $constraint instanceof GreaterThanOrEqual => is_numeric($constraint->value) ? ['minimum' => $constraint->value + 0] : [],
                $constraint instanceof LessThan => is_numeric($constraint->value) ? ['exclusiveMaximum' => $constraint->value + 0] : [],
                $constraint instanceof LessThanOrEqual => is_numeric($constraint->value) ? ['maximum' => $constraint->value + 0] : [],
                $constraint instanceof Email => ['format' => 'email'],
                $constraint instanceof Url => ['format' => 'uri'],
                $constraint instanceof Uuid => ['format' => 'uuid'],
                $constraint instanceof Date => ['format' => 'date'],
                $constraint instanceof DateTime => ['format' => 'date-time'],
                $constraint instanceof Ip => ['format' => match ($constraint->version) { '4', 'v4', 'ipv4' => 'ipv4', '6', 'v6', 'ipv6' => 'ipv6', default => 'ip' }],
                $constraint instanceof Choice => $constraint->choices !== [] ? ['enum' => array_values($constraint->choices)] : [],
                $constraint instanceof Regex => $constraint->match ? $this->pattern($constraint->pattern) : [],
                default => [],
            };

            $schema = array_replace($schema, $values);
        }

        if (isset($schema['enum']) && in_array('null', $types, true) && !in_array(null, $schema['enum'], true)) {
            $schema['enum'][] = null;
        }

        return $schema;
    }

    protected function applyProperty(array|object $schema, ?ReflectionProperty $reflection, bool $required): array
    {
        $attributes = $reflection?->getAttributes(Property::class) ?? [];

        if ($attributes === []) {
            return [$schema, $required];
        }

        $attribute = $attributes[0]->newInstance();
        $schema = (array) $schema;

        if (isset($schema['$ref']) && ($attribute->description !== null || $attribute->deprecated)) {
            $schema = ['allOf' => [$schema]];
        }

        $schema = array_replace($schema, array_filter([
            'description' => $attribute->description,
            'format' => $attribute->format,
            'example' => $attribute->example,
            'deprecated' => $attribute->deprecated ?: null,
        ], static fn (mixed $value): bool => $value !== null), $attribute->schema ?? []);

        return [$schema === [] ? (object) [] : $schema, $attribute->required ?? $required];
    }

    protected function pattern(string $pattern): array
    {
        if (strlen($pattern) < 2 || ctype_alnum($pattern[0]) || $pattern[0] === '\\') {
            return ['pattern' => $pattern];
        }

        $delimiter = $pattern[0];
        $end = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'][$delimiter] ?? $delimiter;
        $position = strrpos($pattern, $end);

        return $position === false || $position === 0 ? ['pattern' => $pattern] : ['pattern' => substr($pattern, 1, $position - 1)];
    }

    protected function name(string $class, ?array $groups, string $direction): string
    {
        $attributes = (new ReflectionClass($class))->getAttributes(Schema::class);
        $base = $attributes !== [] && $attributes[0]->newInstance()->name !== null ? (string) $attributes[0]->newInstance()->name : $this->shortName($class);
        $name = $base . ($groups === null || $groups === [] ? '' : '-' . implode('-', array_map(static fn (string $group): string => (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $group), $groups)));

        if (!isset($this->schemas[$name])) {
            return $name;
        }

        return $this->unique($name . ($direction === self::WRITE ? '.Input' : '.Output'));
    }

    protected function unique(string $name): string
    {
        $candidate = $name;
        $index = 2;

        while (isset($this->schemas[$candidate])) {
            $candidate = $name . $index++;
        }

        return $candidate;
    }

    protected function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    protected function splitUnion(string $type): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (str_split($type) as $character) {
            if ($character === '<') {
                $depth++;
            } elseif ($character === '>') {
                $depth--;
            }

            if ($character === '|' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $character;
        }

        $parts[] = trim($current);

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }
}