<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Schema;

class Schema
{
    public array $tables = [];

    public function addTable(Table $table): static
    {
        $this->tables[$table->name] = $table;

        return $this;
    }

    public function hasTable(string $name): bool
    {
        return isset($this->tables[$name]);
    }

    public function getTable(string $name): ?Table
    {
        return $this->tables[$name] ?? null;
    }

    public function without(array $tables): static
    {
        $schema = new static();

        foreach ($this->tables as $name => $table) {
            if (!in_array(strtolower($name), array_map('strtolower', $tables), true)) {
                $schema->addTable($table);
            }
        }

        return $schema;
    }
}