<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Schema;

use NeoPHP\Package\Orm\Metadata\ClassMetadata;
use NeoPHP\Package\Orm\Metadata\MetadataFactory;
use NeoPHP\Package\Orm\Type\Type;

class SchemaFactory
{
    public function __construct(protected MetadataFactory $metadataFactory)
    {
    }

    public function create(?array $metadata = null): Schema
    {
        $schema = new Schema();
        $metadata ??= $this->metadataFactory->getAllMetadata();

        foreach ($metadata as $class) {
            $schema->addTable($this->createTable($class));
        }

        foreach ($metadata as $class) {
            foreach ($class->associations as $association) {
                if ($association['type'] === ClassMetadata::MANY_TO_MANY && $association['owning'] && !$schema->hasTable($association['joinTable'])) {
                    $schema->addTable($this->createJoinTable($class, $association));
                }
            }
        }

        foreach ($schema->tables as $table) {
            foreach ($table->foreignKeys as $key) {
                if (!$table->isIndexed($key->columns)) {
                    $table->addIndex($key->columns);
                }
            }
        }

        return $schema;
    }

    protected function createTable(ClassMetadata $metadata): Table
    {
        $table = new Table($metadata->table);

        foreach ($metadata->fields as $field => $mapping) {
            $table->addColumn(new Column(
                $mapping['column'],
                Type::getSchemaType($mapping['type']),
                $mapping['length'],
                (bool) $mapping['nullable'],
                Column::normalizeDefault($mapping['default']),
                $field === $metadata->identifier && $metadata->isIdGenerated(),
                $mapping['precision'],
                $mapping['scale'],
            ));

            if ($mapping['unique'] && $field !== $metadata->identifier) {
                $table->addIndex([$mapping['column']], true);
            }
        }

        $table->setPrimaryKey([$metadata->getIdentifierColumn()]);

        foreach ($metadata->getOwningToOneAssociations() as $association) {
            $target = $this->metadataFactory->getMetadata($association['target']);
            $table->addColumn($this->referenceColumn($association['joinColumn'], $target, (bool) $association['nullable']));
            $table->addForeignKey([$association['joinColumn']], $target->table, [$target->getIdentifierColumn()], $association['onDelete']);

            if ($association['type'] === ClassMetadata::ONE_TO_ONE) {
                $table->addIndex([$association['joinColumn']], true);
            }
        }

        foreach ($metadata->indexes as $index) {
            $table->addIndex($index['columns'], (bool) $index['unique'], $index['name']);
        }

        return $table;
    }

    protected function createJoinTable(ClassMetadata $metadata, array $association): Table
    {
        $target = $this->metadataFactory->getMetadata($association['target']);
        $table = new Table($association['joinTable']);
        $table->addColumn($this->referenceColumn($association['joinColumn'], $metadata, false));
        $table->addColumn($this->referenceColumn($association['inverseJoinColumn'], $target, false));
        $table->setPrimaryKey([$association['joinColumn'], $association['inverseJoinColumn']]);
        $table->addForeignKey([$association['joinColumn']], $metadata->table, [$metadata->getIdentifierColumn()], 'CASCADE');
        $table->addForeignKey([$association['inverseJoinColumn']], $target->table, [$target->getIdentifierColumn()], 'CASCADE');

        return $table;
    }

    protected function referenceColumn(string $name, ClassMetadata $target, bool $nullable): Column
    {
        $id = $target->fields[$target->identifier];

        return new Column($name, Type::getSchemaType($id['type']), $id['length'], $nullable, null, false, $id['precision'], $id['scale']);
    }
}