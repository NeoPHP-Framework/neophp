<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Contract;

use NeoPHP\Component\Database\Contract\ConnectionInterface;
use NeoPHP\Package\Orm\Schema\Column;
use NeoPHP\Package\Orm\Schema\Schema;
use NeoPHP\Package\Orm\Schema\SchemaDiff;
use NeoPHP\Package\Orm\Schema\Table;

interface PlatformInterface
{
    public function getName(): string;

    public function quoteIdentifier(string $identifier): string;

    public function getColumnDeclaration(Column $column, bool $inlinePrimaryKey = false): string;

    public function getCreateTableSql(Table $table): array;

    public function getMigrationSql(SchemaDiff $diff): array;

    public function introspect(ConnectionInterface $connection): Schema;

    public function columnsEqual(Column $expected, Column $actual): bool;

    public function supportsTransactionalDdl(): bool;
}