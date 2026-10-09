<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Platform;

use NeoPHP\Component\Database\Contract\ConnectionInterface;
use NeoPHP\Package\Orm\Contract\AbstractPlatform;
use NeoPHP\Package\Orm\Schema\Column;
use NeoPHP\Package\Orm\Schema\ForeignKey;
use NeoPHP\Package\Orm\Schema\Index;
use NeoPHP\Package\Orm\Schema\Schema;
use NeoPHP\Package\Orm\Schema\SchemaDiff;
use NeoPHP\Package\Orm\Schema\Table;
use NeoPHP\Package\Orm\Schema\TableDiff;

class SqlitePlatform extends AbstractPlatform
{
    public const TEMPORARY_PREFIX = '__temp__';

    public function getName(): string
    {
        return 'sqlite';
    }

    public function getColumnDeclaration(Column $column, bool $inlinePrimaryKey = false): string
    {
        if ($inlinePrimaryKey) {
            return 'INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL';
        }

        $type = match ($column->type) {
            'string' => 'VARCHAR(' . ($column->length ?? 255) . ')',
            'text' => 'CLOB',
            'integer' => 'INTEGER',
            'smallint' => 'SMALLINT',
            'bigint' => 'BIGINT',
            'float' => 'DOUBLE PRECISION',
            'decimal' => 'NUMERIC(' . ($column->precision ?? 10) . ', ' . ($column->scale ?? 0) . ')',
            'boolean' => 'BOOLEAN',
            'datetime' => 'DATETIME',
            'date' => 'DATE',
            'time' => 'TIME',
            'json' => 'JSON',
            'guid' => 'CHAR(36)',
            default => strtoupper($column->type),
        };
        $default = $this->getDefaultDeclaration($column);

        return $type . ($column->nullable ? ($default === '' ? ' DEFAULT NULL' : '') : ' NOT NULL') . $default;
    }

    public function getCreateTableSql(Table $table): array
    {
        $sql = [$this->getCreateTableStatement($table, true)];

        foreach ($table->indexes as $index) {
            $sql[] = $this->getCreateIndexSql($table->name, $index);
        }

        return $sql;
    }

    public function getMigrationSql(SchemaDiff $diff): array
    {
        $sql = [];

        foreach ($diff->droppedTables as $table) {
            $sql[] = 'DROP TABLE ' . $this->quoteIdentifier($table->name);
        }

        foreach ($diff->createdTables as $table) {
            array_push($sql, ...$this->getCreateTableSql($table));
        }

        foreach ($diff->changedTables as $tableDiff) {
            array_push($sql, ...$this->getAlterTableSql($tableDiff));
        }

        return $sql;
    }

    public function introspect(ConnectionInterface $connection): Schema
    {
        $schema = new Schema();
        $tables = $connection->fetchAllKeyValue("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name");

        foreach ($tables as $name => $createSql) {
            $table = new Table((string) $name);
            $quoted = $this->quoteIdentifier((string) $name);
            $autoincrement = stripos((string) $createSql, 'AUTOINCREMENT') !== false;
            $primary = [];

            foreach ($connection->fetchAllAssociative('PRAGMA table_info(' . $quoted . ')') as $row) {
                [$declared, $length, $scale] = $this->parseType($row['type'] === '' ? 'BLOB' : (string) $row['type']);
                $type = match (true) {
                    in_array($declared, ['varchar', 'character varying', 'nvarchar'], true) => 'string',
                    $declared === 'char' => $length === 36 ? 'guid' : 'string',
                    in_array($declared, ['clob', 'text'], true) => 'text',
                    in_array($declared, ['integer', 'int'], true) => 'integer',
                    $declared === 'smallint' => 'smallint',
                    $declared === 'bigint' => 'bigint',
                    in_array($declared, ['double precision', 'double', 'real', 'float'], true) => 'float',
                    in_array($declared, ['numeric', 'decimal'], true) => 'decimal',
                    $declared === 'boolean' => 'boolean',
                    in_array($declared, ['datetime', 'timestamp'], true) => 'datetime',
                    $declared === 'date' => 'date',
                    $declared === 'time' => 'time',
                    $declared === 'json' => 'json',
                    default => $declared,
                };

                if ((int) $row['pk'] > 0) {
                    $primary[(int) $row['pk']] = (string) $row['name'];
                }

                $table->addColumn(new Column(
                    (string) $row['name'],
                    $type,
                    in_array($type, ['string', 'guid'], true) ? $length : null,
                    (int) $row['notnull'] === 0 && (int) $row['pk'] === 0,
                    $this->normalizeDefault($row['dflt_value'] === null ? null : (string) $row['dflt_value']),
                    $autoincrement && (int) $row['pk'] > 0,
                    $type === 'decimal' ? $length : null,
                    $type === 'decimal' ? ($scale ?? 0) : null,
                ));
            }

            ksort($primary);
            $table->setPrimaryKey(array_values($primary));

            foreach ($connection->fetchAllAssociative('PRAGMA index_list(' . $quoted . ')') as $row) {
                if (($row['origin'] ?? 'c') !== 'c') {
                    continue;
                }

                $columns = array_map(static fn (array $info): string => (string) $info['name'], $connection->fetchAllAssociative('PRAGMA index_info(' . $this->quoteIdentifier((string) $row['name']) . ')'));
                $table->indexes[(string) $row['name']] = new Index((string) $row['name'], $columns, (int) $row['unique'] === 1);
            }

            $keys = [];

            foreach ($connection->fetchAllAssociative('PRAGMA foreign_key_list(' . $quoted . ')') as $row) {
                $keys[(int) $row['id']]['columns'][(int) $row['seq']] = (string) $row['from'];
                $keys[(int) $row['id']]['foreignColumns'][(int) $row['seq']] = (string) $row['to'];
                $keys[(int) $row['id']]['foreignTable'] = (string) $row['table'];
                $keys[(int) $row['id']]['onDelete'] = (string) $row['on_delete'];
            }

            foreach ($keys as $key) {
                $table->addForeignKey(array_values($key['columns']), $key['foreignTable'], array_values($key['foreignColumns']), $key['onDelete']);
            }

            $schema->addTable($table);
        }

        return $schema;
    }

    protected function inlinePrimaryKey(Table $table): ?string
    {
        if (count($table->primaryKey) !== 1) {
            return null;
        }

        $column = $table->getColumn($table->primaryKey[0]);

        return $column !== null && $column->autoincrement ? $column->name : null;
    }

    protected function getAlterTableSql(TableDiff $diff): array
    {
        if (!$diff->hasColumnChanges() && $diff->addedForeignKeys === [] && $diff->droppedForeignKeys === []) {
            $sql = [];

            foreach ($diff->droppedIndexes as $index) {
                $sql[] = $this->getDropIndexSql($diff->getName(), $index);
            }

            foreach ($diff->addedIndexes as $index) {
                $sql[] = $this->getCreateIndexSql($diff->getName(), $index);
            }

            return $sql;
        }

        $name = $diff->getName();
        $temporary = clone $diff->to;
        $temporary->name = self::TEMPORARY_PREFIX . $name;
        $common = array_values(array_filter(array_keys($diff->to->columns), static fn (string $column): bool => $diff->from->hasColumn($column)));
        $columns = $this->quoteColumns($common);
        $sql = [$this->getCreateTableStatement($temporary, true)];

        if ($common !== []) {
            $sql[] = 'INSERT INTO ' . $this->quoteIdentifier($temporary->name) . ' (' . $columns . ') SELECT ' . $columns . ' FROM ' . $this->quoteIdentifier($name);
        }

        $sql[] = 'DROP TABLE ' . $this->quoteIdentifier($name);
        $sql[] = 'ALTER TABLE ' . $this->quoteIdentifier($temporary->name) . ' RENAME TO ' . $this->quoteIdentifier($name);

        foreach ($diff->to->indexes as $index) {
            $sql[] = $this->getCreateIndexSql($name, $index);
        }

        return $sql;
    }

    protected function getForeignKeyDeclaration(ForeignKey $key): string
    {
        return 'FOREIGN KEY (' . $this->quoteColumns($key->columns) . ') REFERENCES ' . $this->quoteIdentifier($key->foreignTable) . ' (' . $this->quoteColumns($key->foreignColumns) . ')' . ($key->onDelete !== null ? ' ON DELETE ' . $key->onDelete : '');
    }

    protected function getChangeColumnSql(string $table, Column $from, Column $to): array
    {
        return [];
    }
}