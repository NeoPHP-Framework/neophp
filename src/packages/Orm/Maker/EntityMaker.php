<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Maker;

use BackedEnum;
use FilesystemIterator;
use NeoPHP\Package\Orm\Collection\ArrayCollection;
use NeoPHP\Package\Orm\Contract\CollectionInterface;
use NeoPHP\Package\Orm\Exception\OrmException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use SplFileInfo;

class EntityMaker extends AbstractMaker
{
    public const TYPES = [
        'string' => ['?string', null],
        'text' => ['?string', 'text'],
        'integer' => ['?int', null],
        'int' => ['?int', null],
        'smallint' => ['?int', 'smallint'],
        'bigint' => ['?int', 'bigint'],
        'float' => ['?float', null],
        'decimal' => ['?string', 'decimal'],
        'boolean' => ['?bool', null],
        'bool' => ['?bool', null],
        'datetime' => ['?\DateTime', null],
        'datetime_immutable' => ['?\DateTimeImmutable', null],
        'date' => ['?\DateTime', 'date'],
        'date_immutable' => ['?\DateTimeImmutable', 'date_immutable'],
        'time' => ['?\DateTime', 'time'],
        'time_immutable' => ['?\DateTimeImmutable', 'time_immutable'],
        'json' => ['?array', null],
        'guid' => ['?string', 'guid'],
        'uuid' => ['?string', 'guid'],
    ];

    public const RELATIONS = ['manytoone' => 'ManyToOne', 'onetoone' => 'OneToOne', 'onetomany' => 'OneToMany', 'manytomany' => 'ManyToMany'];

    public const UNIQUE_TYPES = ['string', 'integer', 'int', 'smallint', 'bigint', 'guid', 'uuid'];

    public const ORM_NAMESPACE = 'NeoPHP\\Package\\Orm\\Mapping';

    public const IRREGULAR = ['child' => 'children', 'person' => 'people', 'man' => 'men', 'woman' => 'women', 'foot' => 'feet', 'tooth' => 'teeth', 'mouse' => 'mice'];

    public function parseFields(array $specs): array
    {
        $fields = [];

        foreach ($specs as $spec) {
            $spec = trim((string) $spec);
            $name = strstr($spec . ':', ':', true);

            if (isset($fields[rtrim((string) $name, '?')])) {
                throw new OrmException('The field "{name}" is defined twice.', 0, null, ['name' => rtrim((string) $name, '?')]);
            }

            $nullable = str_ends_with($spec, '?');
            $parts = explode(':', rtrim($spec, '?'));
            $name = array_shift($parts);
            $type = strtolower($parts[0] ?? 'string');

            self::assertFieldName($name);

            if (isset(self::RELATIONS[$type])) {
                if (!isset($parts[1]) || $parts[1] === '') {
                    throw new OrmException('The relation "{spec}" needs a target entity: {name}:{type}:Target.', 0, null, ['spec' => $spec, 'name' => $name, 'type' => self::RELATIONS[$type]]);
                }

                if (count($parts) > 3 || (isset($parts[2]) && self::RELATIONS[$type] !== 'OneToMany')) {
                    throw new OrmException('The relation "{spec}" has too many parts: {name}:{type}:Target (OneToMany accepts :mappedBy).', 0, null, ['spec' => $spec, 'name' => $name, 'type' => self::RELATIONS[$type]]);
                }

                $fields[$name] = ['name' => $name, 'relation' => self::RELATIONS[$type], 'target' => $parts[1], 'mappedBy' => $parts[2] ?? null, 'nullable' => $nullable];
                continue;
            }

            if ($type === 'enum') {
                if (!isset($parts[1]) || $parts[1] === '') {
                    throw new OrmException('The enum field "{spec}" needs a class: {name}:enum:App\\Enum\\Status.', 0, null, ['spec' => $spec, 'name' => $name]);
                }

                $fields[$name] = ['name' => $name, 'type' => 'enum', 'enum' => ltrim($parts[1], '\\'), 'nullable' => $nullable, 'length' => null];
                continue;
            }

            if (!isset(self::TYPES[$type])) {
                throw new OrmException('Unknown type "{type}" for the field "{name}". Types: {types}, enum:Class, ManyToOne:Target, OneToOne:Target, OneToMany:Target[:mappedBy], ManyToMany:Target.', 0, null, [
                    'type' => $type,
                    'name' => $name,
                    'types' => implode(', ', array_keys(self::TYPES)),
                ]);
            }

            foreach (array_slice($parts, 1) as $part) {
                if (!ctype_digit($part) || ($type !== 'string' && $type !== 'decimal') || count($parts) > ($type === 'decimal' ? 3 : 2)) {
                    throw new OrmException('The field "{spec}" is not valid: use name:string:length or name:decimal:precision:scale.', 0, null, ['spec' => $spec]);
                }
            }

            $fields[$name] = [
                'name' => $name,
                'type' => $type,
                'nullable' => $nullable,
                'length' => $type === 'string' && isset($parts[1]) ? (int) $parts[1] : null,
                'precision' => $type === 'decimal' ? (int) ($parts[1] ?? 10) : null,
                'scale' => $type === 'decimal' ? (int) ($parts[2] ?? 2) : null,
            ];
        }

        return $fields;
    }

    public function make(string $name, array $fields = [], ?string $repositoryClass = null, bool $force = false): array
    {
        [$class, $file, $created, $manipulators] = $this->plan($name, $fields, $repositoryClass, $force);

        foreach ($manipulators as $path => $manipulator) {
            $this->write($path, $manipulator->getSource(), true);
        }

        return [$class, $file, $created, array_values(array_filter(array_keys($manipulators), static fn (string $path): bool => $path !== $file))];
    }

    public function check(string $name, array $fields, ?string $repositoryClass = null, bool $force = false): void
    {
        $this->plan($name, $fields, $repositoryClass, $force);
    }

    public function exists(string $name): bool
    {
        return is_file($this->resolve($name)[1]);
    }

    public function hasProperty(string $name, string $property): bool
    {
        $class = $this->classOf($name);
        $file = $this->fileOf($class);

        return is_file($file) && (ClassManipulator::fromFile($file)->hasProperty($property) || (class_exists($class) && property_exists($class, $property)));
    }

    public function classOf(string $name): string
    {
        $name = ltrim(str_replace('/', '\\', $name), '\\');

        return str_contains($name, '\\') && str_starts_with($name, trim($this->namespace, '\\') . '\\') ? $name : (str_contains($name, '\\') && class_exists($name) ? $name : $this->resolve($name)[0]);
    }

    public function fileOf(string $class): string
    {
        $class = ltrim($class, '\\');
        $prefix = trim($this->namespace, '\\') . '\\';

        if (!str_starts_with($class, $prefix)) {
            throw new OrmException('The entity "{class}" is not in the namespace {namespace}: it cannot be modified.', 0, null, ['class' => $class, 'namespace' => $prefix]);
        }

        return rtrim($this->path, '/\\') . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
    }

    public function getEntities(): array
    {
        if (!is_dir($this->path)) {
            return [];
        }

        $entities = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen(rtrim(str_replace('\\', '/', $this->path), '/'))), '/');
            $entities[] = str_replace('/', '\\', substr($relative, 0, -4));
        }

        sort($entities);

        return $entities;
    }

    public function getEnums(string $directory, string $namespace): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $enums = [];

        foreach (glob(rtrim($directory, '/\\') . '/*.php') ?: [] as $file) {
            $class = trim($namespace, '\\') . '\\' . basename($file, '.php');

            if (enum_exists($class) && is_subclass_of($class, BackedEnum::class)) {
                $enums[] = $class;
            }
        }

        sort($enums);

        return $enums;
    }

    public static function guess(string $name, array $entities = []): array
    {
        $lower = strtolower($name);
        $guess = ['type' => 'string', 'length' => 255, 'unique' => false, 'nullable' => false, 'target' => null];
        $studly = ucfirst($name);
        $singular = ucfirst(self::singularize($name));

        foreach ($entities as $entity) {
            $short = substr($entity, (int) strrpos('\\' . $entity, '\\'));

            if ($short === $studly) {
                return ['type' => 'ManyToOne', 'target' => $entity] + $guess;
            }

            if ($short === $singular && $singular !== $studly) {
                return ['type' => 'relation', 'target' => $entity] + $guess;
            }
        }

        return match (true) {
            in_array($lower, ['email', 'mail'], true), str_ends_with($lower, 'email') => ['length' => 180, 'unique' => $lower === 'email'] + $guess,
            in_array($lower, ['slug', 'username', 'login', 'reference', 'code', 'sku'], true) => ['unique' => true] + $guess,
            in_array($lower, ['password', 'token', 'hash'], true) => $guess,
            in_array($lower, ['phone', 'mobile', 'telephone', 'fax'], true) => ['length' => 20] + $guess,
            in_array($lower, ['zipcode', 'postcode', 'postalcode'], true) => ['length' => 10] + $guess,
            in_array($lower, ['uuid', 'guid'], true) => ['type' => 'uuid', 'unique' => true] + $guess,
            in_array($lower, ['createdat', 'updatedat'], true) => ['type' => 'datetime_immutable'] + $guess,
            str_ends_with($name, 'At') => ['type' => 'datetime_immutable', 'nullable' => true] + $guess,
            str_ends_with($name, 'On') || str_ends_with($lower, 'date') || in_array($lower, ['birthday', 'birthdate'], true) => ['type' => 'date_immutable'] + $guess,
            preg_match('/^(is|has|can|should|allow|enable)[A-Z]/', $name) === 1 || in_array($lower, ['active', 'enabled', 'published', 'verified', 'visible', 'archived', 'deleted', 'featured', 'locked', 'online'], true) => ['type' => 'boolean'] + $guess,
            in_array($lower, ['price', 'amount', 'total', 'cost', 'tax', 'vat', 'balance', 'salary', 'discount'], true) || preg_match('/(Price|Amount|Total|Cost)$/', $name) === 1 => ['type' => 'decimal'] + $guess,
            in_array($lower, ['latitude', 'longitude', 'lat', 'lng', 'rate', 'ratio', 'score', 'weight', 'height', 'width'], true) => ['type' => 'float'] + $guess,
            in_array($lower, ['description', 'content', 'body', 'text', 'bio', 'biography', 'summary', 'comment', 'message', 'notes', 'excerpt', 'about'], true) => ['type' => 'text', 'nullable' => $lower !== 'content' && $lower !== 'body'] + $guess,
            in_array($lower, ['age', 'position', 'quantity', 'stock', 'views', 'rank', 'priority', 'year', 'duration', 'size', 'level', 'number'], true) || preg_match('/(Count|Quantity|Number|Position)$/', $name) === 1 => ['type' => 'integer'] + $guess,
            in_array($lower, ['roles', 'options', 'settings', 'metadata', 'data', 'tags', 'attributes', 'config', 'payload'], true) => ['type' => 'json'] + $guess,
            default => $guess,
        };
    }

    public static function assertFieldName(string $name): void
    {
        if (preg_match('/^[a-z][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new OrmException('The field name "{name}" is not valid: use camelCase (title, publishedAt).', 0, null, ['name' => $name]);
        }

        if (in_array(strtolower($name), ['id', 'this'], true)) {
            throw new OrmException('The field name "{name}" is reserved.', 0, null, ['name' => $name]);
        }
    }

    public static function acceptsNull(string $class, string $method): bool
    {
        if (!class_exists($class) || !method_exists($class, $method)) {
            return false;
        }

        $parameters = (new ReflectionMethod($class, $method))->getParameters();

        return isset($parameters[0]) && $parameters[0]->allowsNull();
    }

    public static function singularize(string $name): string
    {
        foreach (self::IRREGULAR as $singular => $plural) {
            if (self::endsWithWord($name, $plural)) {
                return substr($name, 0, -strlen($plural)) . (ctype_upper($name[strlen($name) - strlen($plural)]) ? ucfirst($singular) : $singular);
            }
        }

        return match (true) {
            preg_match('/(species|series)$/i', $name) === 1 => $name,
            preg_match('/(movie|cookie|zombie|pie|tie|lie|cache|niche|avalanche)s$/i', $name) === 1 => substr($name, 0, -1),
            str_ends_with($name, 'ies') => substr($name, 0, -3) . 'y',
            preg_match('/(sses|xes|zzes|ches|shes)$/', $name) === 1, preg_match('/(stat|bon|vir|camp|bus|foc|radi|syllab|cact|camp|min)uses$/i', $name) === 1 => substr($name, 0, -2),
            str_ends_with($name, 's') && !str_ends_with($name, 'ss') && !str_ends_with($name, 'us') && !str_ends_with($name, 'is') => substr($name, 0, -1),
            default => $name . 'Item',
        };
    }

    public static function pluralize(string $name): string
    {
        foreach (self::IRREGULAR as $singular => $plural) {
            if (self::endsWithWord($name, $singular)) {
                return substr($name, 0, -strlen($singular)) . (ctype_upper($name[strlen($name) - strlen($singular)]) ? ucfirst($plural) : $plural);
            }
        }

        return match (true) {
            preg_match('/[^aeiou]y$/i', $name) === 1 => substr($name, 0, -1) . 'ies',
            preg_match('/(s|x|z|ch|sh)$/i', $name) === 1 => $name . 'es',
            default => $name . 's',
        };
    }

    protected function plan(string $name, array $fields, ?string $repositoryClass, bool $force): array
    {
        [$class, $file] = $this->resolve($name);
        $created = $force || !is_file($file);
        $manipulators = [$file => $created ? new ClassManipulator($this->skeleton($class, $repositoryClass)) : ClassManipulator::fromFile($file)];
        $existing = [$class => !$created];
        $names = [];

        foreach ($fields as $field) {
            $field = $this->normalize($field, $class);

            if (isset($names[$field['name']])) {
                throw new OrmException('The field "{name}" is defined twice.', 0, null, ['name' => $field['name']]);
            }

            $names[$field['name']] = true;
            $this->add($manipulators[$file], $class, $field, $existing[$class]);

            if (isset($field['relation']) && ($field['inverse'] ?? null) !== null) {
                $target = ltrim((string) $field['target'], '\\');
                $targetFile = $this->fileOf($target);

                if (!isset($manipulators[$targetFile])) {
                    if (!is_file($targetFile)) {
                        throw new OrmException('The entity "{class}" does not exist: its file {file} is missing.', 0, null, ['class' => $target, 'file' => $targetFile]);
                    }

                    $manipulators[$targetFile] = ClassManipulator::fromFile($targetFile);
                    $existing[$target] = true;
                }

                $this->add($manipulators[$targetFile], $target, $this->inverseField($field, $class), $existing[$target] ?? false);
            }
        }

        return [$class, $file, $created, $manipulators];
    }

    protected function add(ClassManipulator $manipulator, string $class, array $field, bool $existing): void
    {
        $loaded = $existing && class_exists($class);

        if ($manipulator->hasProperty($field['name']) || ($loaded && property_exists($class, $field['name']))) {
            throw new OrmException('The property "{class}::${name}" already exists.', 0, null, ['class' => $class, 'name' => $field['name']]);
        }

        $snippets = $this->snippets($field, $manipulator);

        foreach ($snippets['methods'] as $method) {
            $methodName = preg_match('/function\s+(\w+)/', $method, $matches) === 1 ? $matches[1] : '';

            if ($manipulator->hasMethod($methodName) || ($loaded && method_exists($class, $methodName))) {
                throw new OrmException('The method "{class}::{method}()" already exists: rename the field "{name}".', 0, null, ['class' => $class, 'method' => $methodName, 'name' => $field['name']]);
            }
        }

        foreach ($snippets['properties'] as $property) {
            $manipulator->addProperty($property);
        }

        $parent = $loaded ? get_parent_class($class) : false;
        $manipulator->addConstructorLines($snippets['constructor'], $parent !== false && method_exists($parent, '__construct'));

        foreach ($snippets['methods'] as $method) {
            $manipulator->addMethod($method);
        }
    }

    protected static function endsWithWord(string $name, string $word): bool
    {
        return strcasecmp($name, $word) === 0 || (strlen($name) > strlen($word) && substr($name, -strlen($word)) === ucfirst($word));
    }

    protected function skeleton(string $class, ?string $repositoryClass): string
    {
        $uses = [self::ORM_NAMESPACE . ' as ORM'];

        if ($repositoryClass !== null) {
            $uses[] = ltrim($repositoryClass, '\\');
        }

        sort($uses);
        $entity = $repositoryClass !== null ? '#[ORM\\Entity(repository: ' . self::shortName($repositoryClass) . '::class)]' : '#[ORM\\Entity]';

        return '<?php' . "\n\n"
            . 'declare(strict_types=1);' . "\n\n"
            . 'namespace ' . self::namespaceOf($class) . ';' . "\n\n"
            . implode("\n", array_map(static fn (string $use): string => 'use ' . $use . ';', $uses)) . "\n\n"
            . $entity . "\n"
            . 'class ' . self::shortName($class) . "\n"
            . "{\n"
            . "    #[ORM\\Id]\n    #[ORM\\GeneratedValue]\n    #[ORM\\Column]\n    private ?int \$id = null;\n\n"
            . "    public function getId(): ?int\n    {\n        return \$this->id;\n    }\n"
            . "}\n";
    }

    protected function normalize(array $field, string $owner): array
    {
        self::assertFieldName((string) ($field['name'] ?? ''));

        if (!isset($field['relation'])) {
            return $field + ['nullable' => false, 'length' => null, 'precision' => null, 'scale' => null, 'unique' => false];
        }

        $field['target'] = $this->classOf((string) $field['target']);
        $field += ['nullable' => true, 'mappedBy' => null, 'inversedBy' => null, 'orphanRemoval' => false, 'inverse' => null, 'otherNullable' => null];

        if ($field['inverse'] !== null) {
            if (in_array($field['relation'], ['ManyToOne', 'ManyToMany', 'OneToOne'], true) && $field['mappedBy'] === null) {
                $field['inversedBy'] = $field['inverse']['name'];
            }

            if ($field['relation'] === 'OneToMany') {
                $field['mappedBy'] = $field['inverse']['name'];
                $field['otherNullable'] = (bool) ($field['inverse']['nullable'] ?? true);
            }
        }

        if ($field['relation'] === 'OneToMany' && $field['mappedBy'] === null) {
            $field['mappedBy'] = lcfirst(self::shortName($owner));
        }

        return $field;
    }

    protected function inverseField(array $field, string $owner): array
    {
        $name = (string) $field['inverse']['name'];

        return match ($field['relation']) {
            'ManyToOne' => ['name' => $name, 'relation' => 'OneToMany', 'target' => $owner, 'mappedBy' => $field['name'], 'orphanRemoval' => (bool) $field['orphanRemoval'], 'otherNullable' => (bool) $field['nullable'], 'nullable' => true, 'inversedBy' => null, 'inverse' => null],
            'OneToMany' => ['name' => $name, 'relation' => 'ManyToOne', 'target' => $owner, 'mappedBy' => null, 'inversedBy' => $field['name'], 'nullable' => (bool) ($field['inverse']['nullable'] ?? true), 'orphanRemoval' => false, 'inverse' => null, 'otherNullable' => null],
            'ManyToMany' => ['name' => $name, 'relation' => 'ManyToMany', 'target' => $owner, 'mappedBy' => $field['name'], 'inversedBy' => null, 'nullable' => true, 'orphanRemoval' => false, 'inverse' => null, 'otherNullable' => null],
            'OneToOne' => ['name' => $name, 'relation' => 'OneToOne', 'target' => $owner, 'mappedBy' => $field['name'], 'inversedBy' => null, 'nullable' => true, 'orphanRemoval' => false, 'inverse' => null, 'otherNullable' => (bool) $field['nullable']],
            default => throw new OrmException('Unknown relation "{relation}".', 0, null, ['relation' => $field['relation']]),
        };
    }

    protected function snippets(array $field, ClassManipulator $manipulator): array
    {
        $snippets = ['properties' => [], 'methods' => [], 'constructor' => []];

        if (isset($field['relation'])) {
            $this->relation($field, $manipulator, $snippets);
        } else {
            $this->field($field, $manipulator, $snippets);
        }

        return $snippets;
    }

    protected function field(array $field, ClassManipulator $manipulator, array &$snippets): void
    {
        $name = $field['name'];
        $orm = $manipulator->import(self::ORM_NAMESPACE, 'ORM');

        if ($field['type'] === 'enum') {
            $phpType = '?' . $manipulator->import($field['enum']);
            $options = $field['nullable'] ? ['nullable: true'] : [];
        } else {
            [$phpType, $columnType] = self::TYPES[$field['type']];

            if (str_contains($phpType, '\\')) {
                $phpType = '?' . $manipulator->import(ltrim(substr($phpType, 1), '\\'));
            }

            $options = [];

            if ($columnType !== null) {
                $options[] = "type: '" . $columnType . "'";
            }

            if ($field['type'] === 'string' && $field['length'] !== null && (int) $field['length'] !== 255) {
                $options[] = 'length: ' . (int) $field['length'];
            }

            if ($field['type'] === 'decimal') {
                $options[] = 'precision: ' . (int) ($field['precision'] ?? 10);
                $options[] = 'scale: ' . (int) ($field['scale'] ?? 2);
            }

            if ($field['nullable']) {
                $options[] = 'nullable: true';
            }

            if (!empty($field['unique'])) {
                $options[] = 'unique: true';
            }
        }

        $snippets['properties'][] = '    #[' . $orm . '\\Column' . ($options === [] ? '' : '(' . implode(', ', $options) . ')') . "]\n    private " . $phpType . ' $' . $name . ' = null;';
        $studly = ucfirst($name);
        $valueType = $field['nullable'] ? $phpType : ltrim($phpType, '?');
        $getter = in_array($field['type'], ['boolean', 'bool'], true) ? (preg_match('/^(is|has|can|should)[A-Z]/', $name) === 1 ? $name : 'is' . $studly) : 'get' . $studly;
        $snippets['methods'][] = '    public function ' . $getter . '(): ' . $phpType . "\n    {\n        return \$this->" . $name . ";\n    }";
        $snippets['methods'][] = '    public function set' . $studly . '(' . $valueType . ' $' . $name . "): static\n    {\n        \$this->" . $name . ' = $' . $name . ";\n\n        return \$this;\n    }";
    }

    protected function relation(array $field, ClassManipulator $manipulator, array &$snippets): void
    {
        $name = $field['name'];
        $target = ltrim((string) $field['target'], '\\');
        $orm = $manipulator->import(self::ORM_NAMESPACE, 'ORM');
        $targetShort = $manipulator->import($target);
        $studly = ucfirst($name);

        if ($field['relation'] === 'ManyToOne' || ($field['relation'] === 'OneToOne' && $field['mappedBy'] === null)) {
            $options = [$targetShort . '::class'];

            if ($field['inversedBy'] !== null) {
                $options[] = "inversedBy: '" . $field['inversedBy'] . "'";
            }

            if (!$field['nullable']) {
                $options[] = 'nullable: false';
            }

            $snippets['properties'][] = '    #[' . $orm . '\\' . $field['relation'] . '(' . implode(', ', $options) . ")]\n    private ?" . $targetShort . ' $' . $name . ' = null;';
            $snippets['methods'][] = '    public function get' . $studly . '(): ?' . $targetShort . "\n    {\n        return \$this->" . $name . ";\n    }";
            $snippets['methods'][] = '    public function set' . $studly . '(' . ($field['nullable'] ? '?' : '') . $targetShort . ' $' . $name . "): static\n    {\n        \$this->" . $name . ' = $' . $name . ";\n\n        return \$this;\n    }";

            return;
        }

        if ($field['relation'] === 'OneToOne') {
            $other = ucfirst((string) $field['mappedBy']);
            $snippets['properties'][] = '    #[' . $orm . '\\OneToOne(' . $targetShort . "::class, mappedBy: '" . $field['mappedBy'] . "')]\n    private ?" . $targetShort . ' $' . $name . ' = null;';
            $snippets['methods'][] = '    public function get' . $studly . '(): ?' . $targetShort . "\n    {\n        return \$this->" . $name . ";\n    }";
            $body = '';

            if ($field['otherNullable'] ?? true) {
                $body .= '        if ($' . $name . ' === null && $this->' . $name . " !== null) {\n            \$this->" . $name . '->set' . $other . "(null);\n        }\n\n";
            }

            $body .= '        if ($' . $name . ' !== null && $' . $name . '->get' . $other . "() !== \$this) {\n            \$" . $name . '->set' . $other . "(\$this);\n        }\n\n";
            $snippets['methods'][] = '    public function set' . $studly . '(?' . $targetShort . ' $' . $name . "): static\n    {\n" . $body . '        $this->' . $name . ' = $' . $name . ";\n\n        return \$this;\n    }";

            return;
        }

        $collection = $manipulator->import(CollectionInterface::class);
        $arrayCollection = $manipulator->import(ArrayCollection::class);
        $singular = self::singularize($name);
        $variable = $singular === 'this' ? 'item' : $singular;
        $singularStudly = ucfirst($singular);
        $snippets['constructor'][] = '        $this->' . $name . ' = new ' . $arrayCollection . '();';
        $getter = '    public function get' . $studly . '(): ' . $collection . "\n    {\n        return \$this->" . $name . ";\n    }";

        if ($field['relation'] === 'OneToMany') {
            $mappedBy = (string) $field['mappedBy'];
            $options = [$targetShort . '::class', "mappedBy: '" . $mappedBy . "'"];

            if ($field['orphanRemoval']) {
                $options[] = 'orphanRemoval: true';
            }

            $nullable = $field['otherNullable'] ?? self::acceptsNull($target, 'set' . ucfirst($mappedBy));
            $snippets['properties'][] = '    #[' . $orm . '\\OneToMany(' . implode(', ', $options) . ")]\n    private " . $collection . ' $' . $name . ';';
            $snippets['methods'][] = $getter;
            $snippets['methods'][] = '    public function add' . $singularStudly . '(' . $targetShort . ' $' . $variable . "): static\n    {\n        if (!\$this->" . $name . '->contains($' . $variable . ")) {\n            \$this->" . $name . '->add($' . $variable . ");\n            \$" . $variable . '->set' . ucfirst($mappedBy) . "(\$this);\n        }\n\n        return \$this;\n    }";
            $snippets['methods'][] = $nullable
                ? '    public function remove' . $singularStudly . '(' . $targetShort . ' $' . $variable . "): static\n    {\n        if (\$this->" . $name . '->removeElement($' . $variable . ') && $' . $variable . '->get' . ucfirst($mappedBy) . "() === \$this) {\n            \$" . $variable . '->set' . ucfirst($mappedBy) . "(null);\n        }\n\n        return \$this;\n    }"
                : '    public function remove' . $singularStudly . '(' . $targetShort . ' $' . $variable . "): static\n    {\n        \$this->" . $name . '->removeElement($' . $variable . ");\n\n        return \$this;\n    }";

            return;
        }

        $options = [$targetShort . '::class'];

        if ($field['mappedBy'] !== null) {
            $options[] = "mappedBy: '" . $field['mappedBy'] . "'";
        } elseif ($field['inversedBy'] !== null) {
            $options[] = "inversedBy: '" . $field['inversedBy'] . "'";
        }

        $sync = $field['mappedBy'] !== null ? ucfirst(self::singularize((string) $field['mappedBy'])) : null;
        $snippets['properties'][] = '    #[' . $orm . '\\ManyToMany(' . implode(', ', $options) . ")]\n    private " . $collection . ' $' . $name . ';';
        $snippets['methods'][] = $getter;
        $snippets['methods'][] = '    public function add' . $singularStudly . '(' . $targetShort . ' $' . $variable . "): static\n    {\n        if (!\$this->" . $name . '->contains($' . $variable . ")) {\n            \$this->" . $name . '->add($' . $variable . ");\n" . ($sync !== null ? '            $' . $variable . '->add' . $sync . "(\$this);\n" : '') . "        }\n\n        return \$this;\n    }";
        $snippets['methods'][] = $sync !== null
            ? '    public function remove' . $singularStudly . '(' . $targetShort . ' $' . $variable . "): static\n    {\n        if (\$this->" . $name . '->removeElement($' . $variable . ")) {\n            \$" . $variable . '->remove' . $sync . "(\$this);\n        }\n\n        return \$this;\n    }"
            : '    public function remove' . $singularStudly . '(' . $targetShort . ' $' . $variable . "): static\n    {\n        \$this->" . $name . '->removeElement($' . $variable . ");\n\n        return \$this;\n    }";
    }
}