<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Maker;

use NeoPHP\Package\Orm\Exception\OrmException;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\Exception\InvalidInputException;
use NeoPHP\Process\Console\IO\Formatter;

class EntityWizard
{
    public const TYPES = [
        'string' => 'Short text (VARCHAR, 255 characters by default)',
        'text' => 'Long text',
        'integer' => 'Integer',
        'smallint' => 'Small integer',
        'bigint' => 'Big integer (stored as int)',
        'float' => 'Floating point number',
        'decimal' => 'Exact number (money): precision and scale',
        'boolean' => 'true / false',
        'datetime_immutable' => 'Date and time (DateTimeImmutable)',
        'datetime' => 'Date and time (DateTime)',
        'date_immutable' => 'Date only (DateTimeImmutable)',
        'date' => 'Date only (DateTime)',
        'time_immutable' => 'Time only (DateTimeImmutable)',
        'time' => 'Time only (DateTime)',
        'json' => 'Array stored as JSON',
        'uuid' => 'UUID string',
        'enum' => 'PHP backed enum',
        'relation' => 'Relation to another entity (the wizard asks the type)',
        'ManyToOne' => 'Many of this entity for one target (e.g. Post -> Category)',
        'OneToMany' => 'One of this entity for many targets (e.g. Category -> Posts)',
        'ManyToMany' => 'Many to many (e.g. Post <-> Tags)',
        'OneToOne' => 'One to one (e.g. User -> Profile)',
    ];

    public const RELATION_TYPES = ['ManyToOne', 'OneToMany', 'ManyToMany', 'OneToOne'];

    protected string $name = '';

    protected string $class = '';

    protected bool $exists = false;

    protected array $fields = [];

    public function __construct(protected EntityMaker $maker, protected OutputInterface $output, protected string $enumDirectory = '', protected string $enumNamespace = 'App\\Enum')
    {
    }

    public function askEntity(): string
    {
        return (string) $this->output->select('Class name of the entity to create or update (e.g. BlogPost)', $this->maker->getEntities(), null, false, function (mixed $value): string {
            $value = trim((string) $value);

            if ($value === '') {
                throw new InvalidInputException('A value is required.');
            }

            try {
                $this->maker->resolve($value);
            } catch (OrmException $exception) {
                throw new InvalidInputException($exception->getMessage());
            }

            return $value;
        });
    }

    public function run(string $name): array
    {
        [$this->class, , $this->name] = $this->maker->resolve($name);
        $this->exists = $this->maker->exists($name);
        $this->fields = [];

        $this->output->newLine();
        $this->output->text($this->exists ? sprintf('Your entity <info>%s</info> already exists! So let\'s add some new fields!', $this->class) : sprintf('The entity <info>%s</info> and its repository will be created.', $this->class));

        while (true) {
            $this->output->newLine();
            $field = $this->askField();

            if ($field === null) {
                break;
            }

            try {
                $this->maker->check($this->name, [...array_values($this->fields), $field]);
            } catch (OrmException $exception) {
                $this->output->error($exception->getMessage() . ' The field was not added.');
                continue;
            }

            $this->fields[$field['name']] = $field;
            $this->output->text(sprintf('<success>+</success> %s <muted>(%s)</muted>', $field['name'], $this->describe($field)));
        }

        return $this->fields;
    }

    public function describe(array $field): string
    {
        if (isset($field['relation'])) {
            $target = substr((string) $field['target'], (int) strrpos((string) $field['target'], '\\') + 1);
            $inverse = ($field['inverse'] ?? null) !== null ? ', ' . $target . '::$' . $field['inverse']['name'] : '';
            $nullable = in_array($field['relation'], ['ManyToOne', 'OneToOne'], true) && !$field['nullable'] ? ', not null' : '';

            return Formatter::escape($field['relation'] . ' ' . $target . $nullable . $inverse);
        }

        $details = match ($field['type']) {
            'string' => 'string ' . $field['length'],
            'decimal' => 'decimal ' . $field['precision'] . ',' . $field['scale'],
            'enum' => 'enum ' . $field['enum'],
            default => $field['type'],
        };

        return Formatter::escape($details . ($field['nullable'] ? ', nullable' : '') . (!empty($field['unique']) ? ', unique' : ''));
    }

    protected function askField(): ?array
    {
        $property = $this->output->ask('New property name (press <return> to stop adding fields)', null, function (mixed $value): ?string {
            $value = trim((string) $value);

            if ($value === '') {
                return null;
            }

            try {
                EntityMaker::assertFieldName($value);
            } catch (OrmException $exception) {
                throw new InvalidInputException($exception->getMessage());
            }

            if ($this->isTaken($this->class, $value)) {
                throw new InvalidInputException(sprintf('The property "%s" already exists.', $value));
            }

            return $value;
        });

        if ($property === null) {
            return null;
        }

        $guess = EntityMaker::guess($property, $this->maker->getEntities());

        while (true) {
            $type = (string) $this->output->select('Field type (enter ? to see all types)', self::TYPES, $guess['type']);

            if ($type === 'relation' || in_array($type, self::RELATION_TYPES, true)) {
                return $this->askRelation($property, $type, $guess['target'], $guess['type'] === 'relation');
            }

            if ($type !== 'enum' || $this->maker->getEnums($this->enumDirectory, $this->enumNamespace) !== []) {
                break;
            }

            $this->output->error(sprintf('No backed enum found in %s: create one first (enum Status: string { case Draft = \'draft\'; }).', $this->enumNamespace));
        }

        $field = ['name' => $property, 'type' => $type, 'nullable' => false, 'length' => null, 'precision' => null, 'scale' => null, 'unique' => false];

        if ($type === 'string') {
            $field['length'] = (int) $this->output->ask('Field length', (string) $guess['length'], self::integer(1, 65535));
        }

        if ($type === 'decimal') {
            $field['precision'] = (int) $this->output->ask('Precision (total number of digits)', '10', self::integer(1, 65));
            $field['scale'] = (int) $this->output->ask('Scale (number of decimals)', '2', self::integer(0, $field['precision']));
        }

        if ($type === 'enum') {
            $enums = [];

            foreach ($this->maker->getEnums($this->enumDirectory, $this->enumNamespace) as $enum) {
                $enums[$enum] = substr($enum, (int) strrpos($enum, '\\') + 1);
            }

            $field['enum'] = (string) $this->output->select('Enum class', $enums);
        }

        if (in_array($type, EntityMaker::UNIQUE_TYPES, true)) {
            $field['unique'] = $this->output->confirm('Must the value be unique (unique)', (bool) $guess['unique']);
        }

        $field['nullable'] = $this->output->confirm('Can this field be null in the database (nullable)', (bool) $guess['nullable']);

        return $field;
    }

    protected function askRelation(string $property, string $type, ?string $guessTarget, bool $plural): array
    {
        $owner = substr($this->class, (int) strrpos($this->class, '\\') + 1);
        $targets = $this->maker->getEntities();

        if (!in_array($this->name, $targets, true)) {
            $targets[] = $this->name;
            sort($targets);
        }

        $target = (string) $this->output->select('What class should this entity be related to?', $targets, $guessTarget !== null && in_array($guessTarget, $targets, true) ? $guessTarget : null);
        $targetClass = $this->maker->classOf($target);
        $targetShort = substr($targetClass, (int) strrpos($targetClass, '\\') + 1);
        $self = $targetClass === $this->class;

        if ($type === 'relation') {
            $type = (string) $this->output->select('Relation type (enter ? to see all types)', [
                'ManyToOne' => sprintf('Each %1$s relates to (has) one %2$s. Each %2$s can relate to (have) many %1$s objects.', $owner, $targetShort),
                'OneToMany' => sprintf('Each %1$s can relate to (have) many %2$s objects. Each %2$s relates to (has) one %1$s.', $owner, $targetShort),
                'ManyToMany' => sprintf('Each %1$s can relate to many %2$s objects. Each %2$s can also relate to many %1$s objects.', $owner, $targetShort),
                'OneToOne' => sprintf('Each %1$s relates to exactly one %2$s. Each %2$s also relates to exactly one %1$s.', $owner, $targetShort),
            ], $plural ? null : 'ManyToOne');
        }

        $field = ['name' => $property, 'relation' => $type, 'target' => $targetClass, 'nullable' => true, 'orphanRemoval' => false, 'inverse' => null];
        $ownerVar = lcfirst($owner);
        $targetVar = lcfirst($targetShort);

        if ($type === 'ManyToOne' || $type === 'OneToOne') {
            $field['nullable'] = $this->output->confirm(sprintf('Is the %s.%s property allowed to be null (nullable)', $owner, $property), true);
            $inverseName = $type === 'ManyToOne' ? ($self ? 'children' : EntityMaker::pluralize($ownerVar)) : ($self ? 'inverse' . ucfirst($property) : $ownerVar);
            $question = $type === 'ManyToOne'
                ? sprintf('Do you want to add a new property to %s so that you can access/update %s objects from it - e.g. $%s->get%s()', $targetShort, $owner, $targetVar, ucfirst($inverseName))
                : sprintf('Do you want to add a new property to %s so that you can access the %s object from it - e.g. $%s->get%s()', $targetShort, $owner, $targetVar, ucfirst($inverseName));

            if ($this->output->confirm($question, true)) {
                $field['inverse'] = ['name' => $this->askInverseName($targetClass, $targetShort, $inverseName, $property)];

                if ($type === 'ManyToOne' && !$field['nullable']) {
                    $field['orphanRemoval'] = $this->output->confirm(sprintf('Do you want to delete the orphaned %s objects (orphanRemoval)? A %s is orphaned when it is removed from its %s', $owner, $owner, $targetShort), false);
                }
            }

            return $field;
        }

        if ($type === 'OneToMany') {
            $this->output->text(sprintf('A new property will also be added to <info>%s</info> so that you can access and set the related %s object from it.', $targetShort, $owner));
            $inverse = $this->askInverseName($targetClass, $targetShort, $self ? 'parent' : $ownerVar, $property);
            $nullable = $this->output->confirm(sprintf('Is the %s.%s property allowed to be null (nullable)', $targetShort, $inverse), true);
            $field['inverse'] = ['name' => $inverse, 'nullable' => $nullable];

            if (!$nullable) {
                $field['orphanRemoval'] = $this->output->confirm(sprintf('Do you want to delete the orphaned %s objects (orphanRemoval)? A %s is orphaned when it is removed from its %s', $targetShort, $targetShort, $owner), false);
            }

            return $field;
        }

        $inverseName = $self ? 'inverse' . ucfirst($property) : EntityMaker::pluralize($ownerVar);

        if ($this->output->confirm(sprintf('Do you want to add a new property to %s so that you can access/update %s objects from it - e.g. $%s->get%s()', $targetShort, $owner, $targetVar, ucfirst($inverseName)), true)) {
            $field['inverse'] = ['name' => $this->askInverseName($targetClass, $targetShort, $inverseName, $property)];
        }

        return $field;
    }

    protected function askInverseName(string $targetClass, string $targetShort, string $default, string $property): string
    {
        return (string) $this->output->ask(sprintf('New field name inside %s', $targetShort), $default, function (mixed $value) use ($targetClass, $property): string {
            $value = trim((string) $value);

            try {
                EntityMaker::assertFieldName($value);
            } catch (OrmException $exception) {
                throw new InvalidInputException($exception->getMessage());
            }

            if (($targetClass === $this->class && $value === $property) || $this->isTaken($targetClass, $value)) {
                throw new InvalidInputException(sprintf('The property "%s" already exists in %s.', $value, $targetClass));
            }

            return $value;
        });
    }

    protected function isTaken(string $class, string $property): bool
    {
        foreach ($this->fields as $field) {
            if ($class === $this->class && $field['name'] === $property) {
                return true;
            }

            if (isset($field['relation']) && ($field['inverse'] ?? null) !== null && $field['target'] === $class && $field['inverse']['name'] === $property) {
                return true;
            }
        }

        try {
            return $this->maker->hasProperty(substr($class, strlen(trim($this->maker->getNamespace(), '\\')) + 1), $property);
        } catch (OrmException) {
            return false;
        }
    }

    protected static function integer(int $min, int $max): callable
    {
        return static function (mixed $value) use ($min, $max): string {
            $value = trim((string) $value);

            if (!ctype_digit($value) || (int) $value < $min || (int) $value > $max) {
                throw new InvalidInputException(sprintf('Enter a number between %d and %d.', $min, $max));
            }

            return $value;
        };
    }
}