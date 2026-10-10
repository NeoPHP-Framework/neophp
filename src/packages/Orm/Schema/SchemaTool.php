<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Schema;

use NeoPHP\Package\Orm\Metadata\MetadataFactory;
use NeoPHP\Package\Orm\OrmManagerInterface;

class SchemaTool
{
    protected ?MetadataFactory $metadataFactory = null;

    public function __construct(protected OrmManagerInterface $orm, protected array $ignoredTables = [])
    {
    }

    public function forMetadata(MetadataFactory $metadataFactory, array $ignoredTables = []): static
    {
        $tool = clone $this;
        $tool->metadataFactory = $metadataFactory;
        $tool->ignoredTables = $ignoredTables;

        return $tool;
    }

    public function getCurrentSchema(): Schema
    {
        $schema = $this->orm->getPlatform()->introspect($this->orm->getConnection())->without($this->ignoredTables);

        if ($this->metadataFactory === null) {
            return $schema;
        }

        $tables = array_map('strtolower', array_keys($this->getTargetSchema()->tables));

        return $schema->without(array_values(array_filter(array_keys($schema->tables), static fn (string|int $name): bool => !in_array(strtolower((string) $name), $tables, true))));
    }

    public function getTargetSchema(): Schema
    {
        return (new SchemaFactory($this->metadataFactory ?? $this->orm->getMetadataFactory()))->create()->without($this->ignoredTables);
    }

    public function getDiff(): SchemaDiff
    {
        return (new Comparator($this->orm->getPlatform()))->compare($this->getCurrentSchema(), $this->getTargetSchema());
    }

    public function getMigrationSql(): array
    {
        $current = $this->getCurrentSchema();
        $target = $this->getTargetSchema();
        $platform = $this->orm->getPlatform();
        $comparator = new Comparator($platform);

        return [
            $platform->getMigrationSql($comparator->compare($current, $target)),
            $platform->getMigrationSql($comparator->compare($target, $current)),
        ];
    }

    public function getCreateSql(): array
    {
        $platform = $this->orm->getPlatform();

        return $platform->getMigrationSql((new Comparator($platform))->compare(new Schema(), $this->getTargetSchema()));
    }
}