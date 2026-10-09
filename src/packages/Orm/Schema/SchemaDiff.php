<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Schema;

class SchemaDiff
{
    public array $createdTables = [];

    public array $droppedTables = [];

    public array $changedTables = [];

    public function __construct(public Schema $from, public Schema $to)
    {
    }

    public function isEmpty(): bool
    {
        return $this->createdTables === [] && $this->droppedTables === [] && $this->changedTables === [];
    }
}