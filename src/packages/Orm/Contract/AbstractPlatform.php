<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Contract;

use NeoPHP\Package\Orm\Exception\OrmException;
use NeoPHP\Package\Orm\Schema\Column;
use NeoPHP\Package\Orm\Schema\ForeignKey;
use NeoPHP\Package\Orm\Schema\Index;
use NeoPHP\Package\Orm\Schema\SchemaDiff;
use NeoPHP\Package\Orm\Schema\Table;
use NeoPHP\Package\Orm\Schema\TableDiff;

abstract class AbstractPlatform implements PlatformInterface
{
    public const IDENTIFIER_QUOTE = '"';

    public function quoteIdentifier(string $identifier): string
    {
        $quote = static::IDENTIFIER_QUOTE;

        return $quote . str_replace($quote, $quote . $quote, $identifier) . $quote;
    }

    public function columnsEqual(Column $expected, Column $actual): bool
    {
        if (!$this->typesEqual($expected->type, $actual->type)) {
            return false;
        }

        if ($expected->type === 'string' && $actual->type === 'string' && (int) $expected->length !== (int) $actual->length) {
            return false;
        }

        if ($expected->type === 'decimal' && ((int) $expected->precision !== (int) $actual->precision || (int) $expected->scale !== (int) $actual->scale)) {
            return false;
        }

        return $expected->nullable === $actual->nullable
            && $expected->autoincrement === $actual->autoincrement
            && ($expected->autoincrement || $expected->default === $actual->default);
    }

    public function supportsTransactionalDdl(): bool
    {
        return true;
    }

    public function getCreateTableSql(Table $table): array
    {
        $sql = [$this->getCreateTableStatement($table, false)];

        foreach ($table->indexes as $index) {
            $sql[] = $this->getCreateIndexSql($table->name, $index);
        }

        return $sql;
    }

    public function getMigrationSql(SchemaDiff $diff): array
    {
        $sql = [];

        foreach ($diff->changedTables as $tableDiff) {
            foreach ($tableDiff->droppedForeignKeys as $key) {
                $sql[] = $this->getDropForeignKeySql($tableDiff->getName(), $key);
            }
        }

        foreach ($diff->droppedTables as $table) {
            foreach ($table->foreignKeys as $key) {
                $sql[] = $this->getDropForeignKeySql($table->name, $key);
            }
        }

        foreach ($diff->droppedTables as $table) {
            $sql[] = 'DROP TABLE ' . $this->quoteIdentifier($table->name);
        }

        foreach ($diff->createdTables as $table) {
            array_push($sql, ...$this->getCreateTableSql($table));
        }

        foreach ($diff->changedTables as $tableDiff) {
            array_push($sql, ...$this->getAlterTableSql($tableDiff));
        }

        foreach ($diff->createdTables as $table) {
            foreach ($table->foreignKeys as $key) {
                $sql[] = $this->getCreateForeignKeySql($table->name, $key);
            }
        }

        foreach ($diff->changedTables as $tableDiff) {
            foreach ($tableDiff->addedForeignKeys as $key) {
                $sql[] = $this->getCreateForeignKeySql($tableDiff->getName(), $key);
            }
        }

        return $sql;
    }

    protected function getAlterTableSql(TableDiff $diff): array
    {
        $table = $this->quoteIdentifier($diff->getName());
        $sql = [];

        foreach ($diff->droppedIndexes as $index) {
            $sql[] = $this->getDropIndexSql($diff->getName(), $index);
        }

        foreach ($diff->droppedColumns as $column) {
            $sql[] = 'ALTER TABLE ' . $table . ' DROP ' . $this->quoteIdentifier($column->name);
        }

        foreach ($diff->addedColumns as $column) {
            $sql[] = 'ALTER TABLE ' . $table . ' ADD ' . $this->quoteIdentifier($column->name) . ' ' . $this->getColumnDeclaration($column);
        }

        foreach ($diff->changedColumns as [$from, $to]) {
            array_push($sql, ...$this->getChangeColumnSql($diff->getName(), $from, $to));
        }

        foreach ($diff->addedIndexes as $index) {
            $sql[] = $this->getCreateIndexSql($diff->getName(), $index);
        }

        return $sql;
    }

    protected function getCreateTableStatement(Table $table, bool $withForeignKeys): string
    {
        $inline = $this->inlinePrimaryKey($table);
        $parts = [];

        foreach ($table->columns as $column) {
            $parts[] = $this->quoteIdentifier($column->name) . ' ' . $this->getColumnDeclaration($column, $inline === $column->name);
        }

        if ($inline === null && $table->primaryKey !== []) {
            $parts[] = 'PRIMARY KEY (' . $this->quoteColumns($table->primaryKey) . ')';
        }

        if ($withForeignKeys) {
            foreach ($table->foreignKeys as $key) {
                $parts[] = $this->getForeignKeyDeclaration($key);
            }
        }

        return 'CREATE TABLE ' . $this->quoteIdentifier($table->name) . ' (' . implode(', ', $parts) . ')' . $this->getTableOptions();
    }

    protected function inlinePrimaryKey(Table $table): ?string
    {
        return null;
    }

    protected function getTableOptions(): string
    {
        return '';
    }

    protected function getCreateIndexSql(string $table, Index $index): string
    {
        return 'CREATE ' . ($index->unique ? 'UNIQUE ' : '') . 'INDEX ' . $this->quoteIdentifier($index->name) . ' ON ' . $this->quoteIdentifier($table) . ' (' . $this->quoteColumns($index->columns) . ')';
    }

    protected function getDropIndexSql(string $table, Index $index): string
    {
        return 'DROP INDEX ' . $this->quoteIdentifier($index->name);
    }

    protected function getForeignKeyDeclaration(ForeignKey $key): string
    {
        return 'CONSTRAINT ' . $this->quoteIdentifier($key->name) . ' FOREIGN KEY (' . $this->quoteColumns($key->columns) . ') REFERENCES ' . $this->quoteIdentifier($key->foreignTable) . ' (' . $this->quoteColumns($key->foreignColumns) . ')' . ($key->onDelete !== null ? ' ON DELETE ' . $key->onDelete : '');
    }

    protected function getCreateForeignKeySql(string $table, ForeignKey $key): string
    {
        return 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' ADD ' . $this->getForeignKeyDeclaration($key);
    }

    protected function getDropForeignKeySql(string $table, ForeignKey $key): string
    {
        return 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' DROP CONSTRAINT ' . $this->quoteIdentifier($key->name);
    }

    protected function getDefaultDeclaration(Column $column): string
    {
        if ($column->default === null || $column->autoincrement) {
            return '';
        }

        return ' DEFAULT ' . $this->quoteDefault($column);
    }

    protected function quoteDefault(Column $column): string
    {
        return match ($column->type) {
            'integer', 'smallint', 'bigint', 'float', 'decimal' => is_numeric($column->default) ? (string) $column->default : "'" . str_replace("'", "''", (string) $column->default) . "'",
            'boolean' => $column->default === '1' ? '1' : '0',
            default => "'" . str_replace("'", "''", (string) $column->default) . "'",
        };
    }

    protected function quoteColumns(array $columns): string
    {
        return implode(', ', array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns));
    }

    protected function typesEqual(string $expected, string $actual): bool
    {
        return $expected === $actual;
    }

    protected function normalizeDefault(?string $default): ?string
    {
        if ($default === null) {
            return null;
        }

        $default = trim($default);

        if ($default === '' || strtoupper($default) === 'NULL') {
            return null;
        }

        if (preg_match("/^'(.*)'(?:::[\\w\\s\"\\[\\](),]+)?$/s", $default, $m) === 1) {
            return str_replace("''", "'", $m[1]);
        }

        if (preg_match('/^(.+?)::[\w\s"\[\](),]+$/s', $default, $m) === 1) {
            return $this->normalizeDefault($m[1]);
        }

        if (preg_match('/^\((.*)\)$/s', $default, $m) === 1) {
            return $this->normalizeDefault($m[1]);
        }

        return match (strtolower($default)) {
            'true' => '1',
            'false' => '0',
            default => $default,
        };
    }

    protected function parseType(string $declaration): array
    {
        if (preg_match('/^\s*([a-z ]+?)\s*(?:\(\s*(\d+)\s*(?:,\s*(\d+)\s*)?\))?(\s+unsigned)?\s*$/i', $declaration, $m) !== 1) {
            throw new OrmException('Unable to parse the column type "{type}".', 0, null, ['type' => $declaration]);
        }

        return [strtolower(trim($m[1])), isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null, isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null];
    }

    abstract protected function getChangeColumnSql(string $table, Column $from, Column $to): array;
}