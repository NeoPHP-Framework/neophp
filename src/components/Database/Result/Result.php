<?php

declare(strict_types=1);

namespace NeoPHP\Component\Database\Result;

use Generator;
use IteratorAggregate;
use PDO;
use PDOStatement;
use stdClass;

class Result implements IteratorAggregate
{
    public function __construct(protected PDOStatement $statement)
    {
    }

    public function fetchAssociative(): ?array
    {
        $row = $this->statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function fetchNumeric(): ?array
    {
        $row = $this->statement->fetch(PDO::FETCH_NUM);

        return $row === false ? null : $row;
    }

    public function fetchObject(string $class = stdClass::class, array $arguments = []): ?object
    {
        $object = $this->statement->fetchObject($class, $arguments);

        return $object === false ? null : $object;
    }

    public function fetchOne(): mixed
    {
        $value = $this->statement->fetch(PDO::FETCH_NUM);

        return $value === false ? null : $value[0];
    }

    public function fetchAllAssociative(): array
    {
        return $this->statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchAllNumeric(): array
    {
        return $this->statement->fetchAll(PDO::FETCH_NUM);
    }

    public function fetchAllObjects(string $class = stdClass::class, array $arguments = []): array
    {
        return $this->statement->fetchAll(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, $class, $arguments);
    }

    public function fetchFirstColumn(): array
    {
        return $this->statement->fetchAll(PDO::FETCH_COLUMN);
    }

    public function fetchAllKeyValue(): array
    {
        $values = [];

        while (($row = $this->statement->fetch(PDO::FETCH_NUM)) !== false) {
            $values[$row[0]] = $row[1] ?? null;
        }

        return $values;
    }

    public function fetchAllAssociativeIndexed(): array
    {
        $rows = [];

        while (($row = $this->statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $key = array_shift($row);
            $rows[$key] = $row;
        }

        return $rows;
    }

    public function iterateAssociative(): Generator
    {
        while (($row = $this->statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            yield $row;
        }
    }

    public function iterateNumeric(): Generator
    {
        while (($row = $this->statement->fetch(PDO::FETCH_NUM)) !== false) {
            yield $row;
        }
    }

    public function getIterator(): Generator
    {
        return $this->iterateAssociative();
    }

    public function rowCount(): int
    {
        return $this->statement->rowCount();
    }

    public function columnCount(): int
    {
        return $this->statement->columnCount();
    }

    public function getColumnNames(): array
    {
        $names = [];

        for ($index = 0, $count = $this->columnCount(); $index < $count; $index++) {
            $meta = $this->statement->getColumnMeta($index);
            $names[] = is_array($meta) ? (string) $meta['name'] : (string) $index;
        }

        return $names;
    }

    public function free(): void
    {
        $this->statement->closeCursor();
    }

    public function getStatement(): PDOStatement
    {
        return $this->statement;
    }
}