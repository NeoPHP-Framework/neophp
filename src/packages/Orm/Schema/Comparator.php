<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Schema;

use NeoPHP\Package\Orm\Contract\PlatformInterface;

class Comparator
{
    public function __construct(protected PlatformInterface $platform)
    {
    }

    public function compare(Schema $from, Schema $to): SchemaDiff
    {
        $diff = new SchemaDiff($from, $to);
        $fromTables = $this->lower($from->tables);
        $toTables = $this->lower($to->tables);

        foreach ($toTables as $key => $table) {
            if (!isset($fromTables[$key])) {
                $diff->createdTables[$table->name] = $table;
                continue;
            }

            $tableDiff = $this->compareTable($fromTables[$key], $table);

            if (!$tableDiff->isEmpty()) {
                $diff->changedTables[$table->name] = $tableDiff;
            }
        }

        foreach ($fromTables as $key => $table) {
            if (!isset($toTables[$key])) {
                $diff->droppedTables[$table->name] = $table;
            }
        }

        return $diff;
    }

    public function compareTable(Table $from, Table $to): TableDiff
    {
        $diff = new TableDiff($from, $to);
        $fromColumns = $this->lower($from->columns);
        $toColumns = $this->lower($to->columns);

        foreach ($toColumns as $key => $column) {
            if (!isset($fromColumns[$key])) {
                $diff->addedColumns[$column->name] = $column;
            } elseif (!$this->platform->columnsEqual($column, $fromColumns[$key])) {
                $diff->changedColumns[$column->name] = [$fromColumns[$key], $column];
            }
        }

        foreach ($fromColumns as $key => $column) {
            if (!isset($toColumns[$key])) {
                $diff->droppedColumns[$column->name] = $column;
            }
        }

        $fromIndexes = $this->bySignature($from->indexes);
        $toIndexes = $this->bySignature($to->indexes);

        foreach ($toIndexes as $signature => $index) {
            if (!isset($fromIndexes[$signature])) {
                $diff->addedIndexes[$index->name] = $index;
            }
        }

        foreach ($fromIndexes as $signature => $index) {
            if (!isset($toIndexes[$signature])) {
                $diff->droppedIndexes[$index->name] = $index;
            }
        }

        $fromKeys = $this->bySignature($from->foreignKeys);
        $toKeys = $this->bySignature($to->foreignKeys);

        foreach ($toKeys as $signature => $key) {
            if (!isset($fromKeys[$signature])) {
                $diff->addedForeignKeys[$key->name] = $key;
            }
        }

        foreach ($fromKeys as $signature => $key) {
            if (!isset($toKeys[$signature])) {
                $diff->droppedForeignKeys[$key->name] = $key;
            }
        }

        return $diff;
    }

    protected function lower(array $items): array
    {
        $result = [];

        foreach ($items as $name => $item) {
            $result[strtolower((string) $name)] = $item;
        }

        return $result;
    }

    protected function bySignature(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            $result[$item->getSignature()] = $item;
        }

        return $result;
    }
}