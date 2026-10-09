<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Platform;

use NeoPHP\Component\Database\Contract\ConnectionInterface;
use NeoPHP\Package\Orm\Contract\AbstractPlatform;
use NeoPHP\Package\Orm\Schema\Column;
use NeoPHP\Package\Orm\Schema\ForeignKey;
use NeoPHP\Package\Orm\Schema\Index;
use NeoPHP\Package\Orm\Schema\Schema;
use NeoPHP\Package\Orm\Schema\Table;

class MysqlPlatform extends AbstractPlatform
{
    public const IDENTIFIER_QUOTE = '`';

    public function getName(): string
    {
        return 'mysql';
    }

    public function supportsTransactionalDdl(): bool
    {
        return false;
    }

    public function getColumnDeclaration(Column $column, bool $inlinePrimaryKey = false): string
    {
        $type = match ($column->type) {
            'string' => 'VARCHAR(' . ($column->length ?? 255) . ')',
            'text' => 'LONGTEXT',
            'integer' => 'INT',
            'smallint' => 'SMALLINT',
            'bigint' => 'BIGINT',
            'float' => 'DOUBLE PRECISION',
            'decimal' => 'NUMERIC(' . ($column->precision ?? 10) . ', ' . ($column->scale ?? 0) . ')',
            'boolean' => 'TINYINT(1)',
            'datetime' => 'DATETIME',
            'date' => 'DATE',
            'time' => 'TIME',
            'json' => 'JSON',
            'guid' => 'CHAR(36)',
            default => strtoupper($column->type),
        };

        $default = $column->autoincrement ? ' AUTO_INCREMENT' : $this->getDefaultDeclaration($column);

        return $type . ($column->nullable ? ($default === '' ? ' DEFAULT NULL' : ' NULL') : ' NOT NULL') . $default;
    }

    public function introspect(ConnectionInterface $connection): Schema
    {
        $schema = new Schema();
        $tables = $connection->fetchFirstColumn("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");

        foreach ($tables as $name) {
            $table = new Table((string) $name);
            $columns = $connection->fetchAllAssociative('SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$name]);

            foreach ($columns as $row) {
                $row = array_change_key_case($row, CASE_UPPER);
                $dataType = strtolower((string) $row['DATA_TYPE']);
                $length = $row['CHARACTER_MAXIMUM_LENGTH'] !== null ? (int) $row['CHARACTER_MAXIMUM_LENGTH'] : null;
                $type = match (true) {
                    $dataType === 'varchar' => 'string',
                    $dataType === 'char' => $length === 36 ? 'guid' : 'string',
                    in_array($dataType, ['text', 'tinytext', 'mediumtext', 'longtext'], true) => 'text',
                    $dataType === 'int', $dataType === 'mediumint' => 'integer',
                    $dataType === 'tinyint' => str_starts_with(strtolower((string) $row['COLUMN_TYPE']), 'tinyint(1)') ? 'boolean' : 'smallint',
                    $dataType === 'smallint' => 'smallint',
                    $dataType === 'bigint' => 'bigint',
                    in_array($dataType, ['double', 'float', 'real'], true) => 'float',
                    in_array($dataType, ['decimal', 'numeric'], true) => 'decimal',
                    in_array($dataType, ['datetime', 'timestamp'], true) => 'datetime',
                    $dataType === 'date' => 'date',
                    $dataType === 'time' => 'time',
                    $dataType === 'json' => 'json',
                    default => $dataType,
                };

                $table->addColumn(new Column(
                    (string) $row['COLUMN_NAME'],
                    $type,
                    in_array($type, ['string', 'guid'], true) ? $length : null,
                    $row['IS_NULLABLE'] === 'YES',
                    $this->normalizeDefault($row['COLUMN_DEFAULT'] === null ? null : (string) $row['COLUMN_DEFAULT']),
                    str_contains(strtolower((string) $row['EXTRA']), 'auto_increment'),
                    $type === 'decimal' ? (int) $row['NUMERIC_PRECISION'] : null,
                    $type === 'decimal' ? (int) $row['NUMERIC_SCALE'] : null,
                ));
            }

            $indexes = [];

            foreach ($connection->fetchAllAssociative('SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$name]) as $row) {
                $row = array_change_key_case($row, CASE_UPPER);
                $indexes[(string) $row['INDEX_NAME']]['columns'][] = (string) $row['COLUMN_NAME'];
                $indexes[(string) $row['INDEX_NAME']]['unique'] = (int) $row['NON_UNIQUE'] === 0;
            }

            foreach ($indexes as $indexName => $index) {
                if ($indexName === 'PRIMARY') {
                    $table->setPrimaryKey($index['columns']);
                    continue;
                }

                $table->indexes[$indexName] = new Index($indexName, $index['columns'], $index['unique']);
            }

            $keys = [];

            foreach ($connection->fetchAllAssociative('SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION', [$name]) as $row) {
                $row = array_change_key_case($row, CASE_UPPER);
                $keyName = (string) $row['CONSTRAINT_NAME'];
                $keys[$keyName]['columns'][] = (string) $row['COLUMN_NAME'];
                $keys[$keyName]['foreignColumns'][] = (string) $row['REFERENCED_COLUMN_NAME'];
                $keys[$keyName]['foreignTable'] = (string) $row['REFERENCED_TABLE_NAME'];
                $keys[$keyName]['onDelete'] = (string) $row['DELETE_RULE'];
            }

            foreach ($keys as $keyName => $key) {
                $table->foreignKeys[$keyName] = new ForeignKey($keyName, $key['columns'], $key['foreignTable'], $key['foreignColumns'], $key['onDelete']);
            }

            $schema->addTable($table);
        }

        return $schema;
    }

    protected function typesEqual(string $expected, string $actual): bool
    {
        return $expected === $actual || (in_array($expected, ['json', 'text'], true) && in_array($actual, ['json', 'text'], true));
    }

    protected function getTableOptions(): string
    {
        return ' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB';
    }

    protected function getDropIndexSql(string $table, Index $index): string
    {
        return 'DROP INDEX ' . $this->quoteIdentifier($index->name) . ' ON ' . $this->quoteIdentifier($table);
    }

    protected function getDropForeignKeySql(string $table, ForeignKey $key): string
    {
        return 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' DROP FOREIGN KEY ' . $this->quoteIdentifier($key->name);
    }

    protected function getChangeColumnSql(string $table, Column $from, Column $to): array
    {
        return ['ALTER TABLE ' . $this->quoteIdentifier($table) . ' MODIFY ' . $this->quoteIdentifier($to->name) . ' ' . $this->getColumnDeclaration($to)];
    }
}