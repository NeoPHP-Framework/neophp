<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Schema;

use NeoPHP\Package\Orm\OrmManagerInterface;

class SchemaTool
{
    public function __construct(protected OrmManagerInterface $orm, protected array $ignoredTables = [])
    {
    }

    public function getCurrentSchema(): Schema
    {
        return $this->orm->getPlatform()->introspect($this->orm->getConnection())->without($this->ignoredTables);
    }

    public function getTargetSchema(): Schema
    {
        return (new SchemaFactory($this->orm->getMetadataFactory()))->create()->without($this->ignoredTables);
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