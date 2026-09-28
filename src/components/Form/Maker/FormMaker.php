<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Maker;

use NeoPHP\Component\Form\Contract\AbstractForm;
use NeoPHP\Component\Form\Exception\FormException;
use NeoPHP\Component\Form\FormBuilder;
use NeoPHP\Component\Form\Type\CheckboxType;
use NeoPHP\Component\Form\Type\ColorType;
use NeoPHP\Component\Form\Type\DateTimeType;
use NeoPHP\Component\Form\Type\DateType;
use NeoPHP\Component\Form\Type\EmailType;
use NeoPHP\Component\Form\Type\EnumType;
use NeoPHP\Component\Form\Type\IntegerType;
use NeoPHP\Component\Form\Type\NumberType;
use NeoPHP\Component\Form\Type\PasswordType;
use NeoPHP\Component\Form\Type\TelType;
use NeoPHP\Component\Form\Type\TextareaType;
use NeoPHP\Component\Form\Type\TextType;
use NeoPHP\Component\Form\Type\TimeType;
use NeoPHP\Component\Form\Type\UrlType;
use NeoPHP\Package\Orm\Contract\OrmInterface;
use NeoPHP\Package\Orm\Helper\Form\EntityType;
use NeoPHP\Package\Orm\Metadata\ClassMetadata;

class FormMaker
{
    protected array $uses = [];

    public function __construct(protected string $path, protected string $namespace = 'App\\Form', protected ?OrmInterface $orm = null)
    {
    }

    public function resolve(string $name): array
    {
        $name = trim(str_replace('/', '\\', $name), '\\');

        if (str_starts_with($name, trim($this->namespace, '\\') . '\\')) {
            $name = substr($name, strlen(trim($this->namespace, '\\')) + 1);
        }

        if (!str_ends_with($name, 'Form')) {
            $name .= 'Form';
        }

        if (preg_match('/^([A-Z][A-Za-z0-9_]*\\\\)*[A-Z][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new FormException('The name "{name}" is not a valid class name: use StudlyCase (Post, ContactForm, Admin\Post).', 0, null, ['name' => $name]);
        }

        return [trim($this->namespace, '\\') . '\\' . $name, rtrim($this->path, '/\\') . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $name) . '.php'];
    }

    public function make(string $name, ?string $entityClass = null, bool $force = false): array
    {
        [$class, $file] = $this->resolve($name);

        if (is_file($file) && !$force) {
            throw new FormException('The file "{file}" already exists: use --force to overwrite it.', 0, null, ['file' => $file]);
        }

        $this->uses = [AbstractForm::class => true, FormBuilder::class => true];
        $fields = $entityClass !== null ? $this->entityFields($entityClass) : [['name', TextType::class, []]];
        $lines = [];

        foreach ($fields as [$field, $type, $options]) {
            $this->uses[$type] = true;
            $lines[] = '            ->add(' . var_export($field, true) . ', ' . self::short($type) . '::class' . $this->options($options, 12) . ')';
        }

        if ($entityClass !== null) {
            $this->uses[$entityClass] = true;
        }

        $uses = array_keys($this->uses);
        sort($uses);
        $namespace = substr($class, 0, (int) strrpos($class, '\\'));

        $code = '<?php' . "\n\n"
            . 'declare(strict_types=1);' . "\n\n"
            . 'namespace ' . $namespace . ';' . "\n\n"
            . implode("\n", array_map(static fn (string $use): string => 'use ' . $use . ';', $uses)) . "\n\n"
            . 'class ' . self::short($class) . ' extends AbstractForm' . "\n"
            . "{\n"
            . ($entityClass !== null ? '    protected ?string $entityClass = ' . self::short($entityClass) . "::class;\n\n" : '')
            . "    public function buildForm(FormBuilder \$builder, array \$options): void\n"
            . "    {\n"
            . "        \$builder\n"
            . implode("\n", $lines) . ";\n"
            . "    }\n"
            . "}\n";

        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new FormException('Unable to create the directory "{directory}".', 0, null, ['directory' => $directory]);
        }

        if (file_put_contents($file, $code) === false) {
            throw new FormException('Unable to write the file "{file}".', 0, null, ['file' => $file]);
        }

        return [$class, $file, $fields];
    }

    protected function entityFields(string $entityClass): array
    {
        if ($this->orm === null) {
            throw new FormException('The ORM is required to generate a form from the entity "{entity}".', 0, null, ['entity' => $entityClass]);
        }

        $metadata = $this->orm->getMetadata($entityClass);
        $fields = [];

        foreach ($metadata->fields as $name => $mapping) {
            if ($name === $metadata->identifier && $metadata->generator !== 'none') {
                continue;
            }

            $field = $this->guessField($name, $mapping);

            if ($field !== null) {
                $fields[] = $field;
            }
        }

        foreach ($metadata->associations as $name => $association) {
            if (!$association['owning']) {
                continue;
            }

            $this->uses[$association['target']] = true;
            $options = ['class' => new ClassConstant($association['target'])];
            $target = $this->orm->getMetadata($association['target']);

            foreach (['name', 'title', 'label', 'username', 'email', 'code'] as $candidate) {
                if ($target->hasField($candidate)) {
                    $options['choice_label'] = $candidate;
                    break;
                }
            }

            if ($association['type'] === ClassMetadata::MANY_TO_MANY) {
                $options['multiple'] = true;
                $options['required'] = false;
            } elseif ($association['nullable']) {
                $options['required'] = false;
            }

            $fields[] = [$name, EntityType::class, $options];
        }

        return $fields;
    }

    protected function guessField(string $name, array $mapping): ?array
    {
        $options = $mapping['nullable'] ? ['required' => false] : [];
        $lower = strtolower($name);

        if ($mapping['enumType'] !== null) {
            $this->uses[$mapping['enumType']] = true;

            return [$name, EnumType::class, ['class' => new ClassConstant($mapping['enumType'])] + $options];
        }

        return match ($mapping['type']) {
            'string', 'guid' => [$name, match (true) {
                str_contains($lower, 'email') => EmailType::class,
                str_contains($lower, 'password') => PasswordType::class,
                str_contains($lower, 'url') || str_contains($lower, 'website') => UrlType::class,
                str_contains($lower, 'phone') || str_contains($lower, 'tel') => TelType::class,
                str_contains($lower, 'color') => ColorType::class,
                default => TextType::class,
            }, $options],
            'text' => [$name, TextareaType::class, $options],
            'integer', 'smallint', 'bigint' => [$name, IntegerType::class, $options],
            'float' => [$name, NumberType::class, $options],
            'decimal' => [$name, NumberType::class, ['input' => 'string', 'scale' => (int) $mapping['scale']] + $options],
            'boolean' => [$name, CheckboxType::class, ['required' => false]],
            'datetime' => [$name, DateTimeType::class, ['input' => 'datetime'] + $options],
            'datetime_immutable' => [$name, DateTimeType::class, $options],
            'date' => [$name, DateType::class, ['input' => 'datetime'] + $options],
            'date_immutable' => [$name, DateType::class, $options],
            'time' => [$name, TimeType::class, ['input' => 'datetime'] + $options],
            'time_immutable' => [$name, TimeType::class, $options],
            default => null,
        };
    }

    protected function options(array $options, int $indent): string
    {
        if ($options === []) {
            return '';
        }

        $pad = str_repeat(' ', $indent);
        $lines = [];

        foreach ($options as $key => $value) {
            $lines[] = $pad . '    ' . var_export($key, true) . ' => ' . ($value instanceof ClassConstant ? self::short($value->class) . '::class' : var_export($value, true)) . ',';
        }

        return ", [\n" . implode("\n", $lines) . "\n" . $pad . ']';
    }

    protected static function short(string $class): string
    {
        return substr($class, (int) strrpos($class, '\\') + 1);
    }
}