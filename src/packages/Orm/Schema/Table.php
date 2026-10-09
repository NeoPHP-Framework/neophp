<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Schema;

class Table
{
    public array $columns = [];

    public array $primaryKey = [];

    public array $indexes = [];

    public array $foreignKeys = [];

    public function __construct(public string $name)
    {
    }

    public function addColumn(Column $column): static
    {
        $this->columns[$column->name] = $column;

        return $this;
    }

    public function hasColumn(string $name): bool
    {
        return isset($this->columns[$name]);
    }

    public function getColumn(string $name): ?Column
    {
        return $this->columns[$name] ?? null;
    }

    public function setPrimaryKey(array $columns): static
    {
        $this->primaryKey = array_values($columns);

        return $this;
    }

    public function addIndex(array $columns, bool $unique = false, ?string $name = null): static
    {
        $index = new Index($name ?? self::generateName($unique ? 'UNIQ' : 'IDX', $this->name, $columns), array_values($columns), $unique);

        foreach ($this->indexes as $existing) {
            if ($existing->getSignature() === $index->getSignature()) {
                return $this;
            }
        }

        $this->indexes[$index->name] = $index;

        return $this;
    }

    public function isIndexed(array $columns): bool
    {
        if (array_map('strtolower', array_slice($this->primaryKey, 0, count($columns))) === array_map('strtolower', $columns)) {
            return true;
        }

        foreach ($this->indexes as $index) {
            if ($index->covers($columns)) {
                return true;
            }
        }

        return false;
    }

    public function addForeignKey(array $columns, string $foreignTable, array $foreignColumns, ?string $onDelete = null, ?string $name = null): static
    {
        $key = new ForeignKey($name ?? self::generateName('FK', $this->name, $columns), array_values($columns), $foreignTable, array_values($foreignColumns), $onDelete);
        $this->foreignKeys[$key->name] = $key;

        return $this;
    }

    public static function generateName(string $prefix, string $table, array $columns): string
    {
        return $prefix . '_' . strtoupper(substr(hash('sha256', $table . '|' . implode('|', $columns)), 0, 16));
    }
}