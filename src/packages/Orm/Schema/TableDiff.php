<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Schema;

class TableDiff
{
    public array $addedColumns = [];

    public array $droppedColumns = [];

    public array $changedColumns = [];

    public array $addedIndexes = [];

    public array $droppedIndexes = [];

    public array $addedForeignKeys = [];

    public array $droppedForeignKeys = [];

    public function __construct(public Table $from, public Table $to)
    {
    }

    public function getName(): string
    {
        return $this->to->name;
    }

    public function hasColumnChanges(): bool
    {
        return $this->addedColumns !== [] || $this->droppedColumns !== [] || $this->changedColumns !== [];
    }

    public function isEmpty(): bool
    {
        return !$this->hasColumnChanges() && $this->addedIndexes === [] && $this->droppedIndexes === [] && $this->addedForeignKeys === [] && $this->droppedForeignKeys === [];
    }
}