<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Helper\Form;

use NeoPHP\Component\Form\Accessor\PropertyAccessor;
use NeoPHP\Component\Form\Exception\FormException;
use NeoPHP\Component\Form\Type\ChoiceType;
use NeoPHP\Package\Orm\OrmManagerInterface;
use NeoPHP\Package\Orm\Query\QueryBuilder;
use Stringable;

class EntityType extends ChoiceType
{
    protected array $loaded = [];

    public function __construct(protected OrmManagerInterface $orm, ?PropertyAccessor $accessor = null)
    {
        parent::__construct($accessor);
    }

    public function getParent(): ?string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'entity';
    }

    public function configureOptions(): array
    {
        return [
            'class' => null,
            'query_builder' => null,
            'choices' => null,
        ];
    }

    protected function loadChoices(array $options): array
    {
        $class = $options['class'] ?? null;

        if (!is_string($class) || $class === '') {
            throw new FormException('The "class" option of EntityType is required.');
        }

        $entities = $options['choices'];
        $key = $class . '#' . (is_object($options['query_builder']) ? spl_object_id($options['query_builder']) : '');

        if ($entities === null && isset($this->loaded[$key])) {
            $entities = $this->loaded[$key];
        }

        if ($entities === null) {
            $builder = $options['query_builder'];
            $repository = $this->orm->getRepository($class);

            if (is_callable($builder)) {
                $builder = $builder($repository);
            }

            $entities = match (true) {
                $builder instanceof QueryBuilder => $builder->getResult(),
                $builder === null => $repository->findAll(),
                default => throw new FormException('The "query_builder" option must be a QueryBuilder or a callable returning one.'),
            };
            $this->loaded[$key] = $entities;
        }

        $choices = [];

        foreach ($entities as $entity) {
            $choices[] = [$entity, null];
        }

        return $choices;
    }

    protected function valueOf(mixed $data, array $options, int $index): string
    {
        if (is_object($data) && $options['choice_value'] === null && $this->orm->getMetadataFactory()->isEntity($data)) {
            return (string) $this->orm->getMetadata($data)->getIdentifierValue($data);
        }

        return parent::valueOf($data, $options, $index);
    }

    protected function labelOf(mixed $data, ?string $label, array $options, string $value): string
    {
        if ($options['choice_label'] === null && is_object($data) && !$data instanceof Stringable) {
            return $value;
        }

        return parent::labelOf($data, $label, $options, $value);
    }

    protected function findValue(mixed $data, array $list, array $options): ?string
    {
        $found = parent::findValue($data, $list, $options);

        if ($found !== null || !is_object($data) || !$this->orm->getMetadataFactory()->isEntity($data)) {
            return $found;
        }

        $value = (string) $this->orm->getMetadata($data)->getIdentifierValue($data);

        foreach ($list as $choice) {
            if ($choice['value'] === $value) {
                return $value;
            }
        }

        return null;
    }
}