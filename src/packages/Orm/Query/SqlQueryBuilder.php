<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Query;

use NeoPHP\Component\Database\Contract\ConnectionInterface;
use NeoPHP\Component\Database\Result\Result;
use NeoPHP\Package\Orm\Exception\OrmException;

class SqlQueryBuilder
{
    public const SELECT = 'SELECT';
    public const INSERT = 'INSERT';
    public const UPDATE = 'UPDATE';
    public const DELETE = 'DELETE';

    protected string $type = self::SELECT;

    protected array $select = [];

    protected bool $distinct = false;

    protected ?array $from = null;

    protected array $joins = [];

    protected ?string $where = null;

    protected array $groupBy = [];

    protected ?string $having = null;

    protected array $orderBy = [];

    protected ?int $maxResults = null;

    protected ?int $firstResult = null;

    protected array $values = [];

    protected array $parameters = [];

    public function __construct(protected ConnectionInterface $connection)
    {
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function select(string ...$columns): static
    {
        $this->type = self::SELECT;
        $this->select = $columns === [] ? [] : $columns;

        return $this;
    }

    public function addSelect(string ...$columns): static
    {
        $this->type = self::SELECT;
        array_push($this->select, ...$columns);

        return $this;
    }

    public function distinct(bool $distinct = true): static
    {
        $this->distinct = $distinct;

        return $this;
    }

    public function from(string $table, ?string $alias = null): static
    {
        $this->from = ['table' => $table, 'alias' => $alias];

        return $this;
    }

    public function insert(string $table): static
    {
        $this->type = self::INSERT;
        $this->from = ['table' => $table, 'alias' => null];

        return $this;
    }

    public function update(string $table, ?string $alias = null): static
    {
        $this->type = self::UPDATE;
        $this->from = ['table' => $table, 'alias' => $alias];

        return $this;
    }

    public function delete(string $table, ?string $alias = null): static
    {
        $this->type = self::DELETE;
        $this->from = ['table' => $table, 'alias' => $alias];

        return $this;
    }

    public function join(string $table, string $alias, string $condition): static
    {
        return $this->innerJoin($table, $alias, $condition);
    }

    public function innerJoin(string $table, string $alias, string $condition): static
    {
        $this->joins[] = ['type' => 'INNER', 'table' => $table, 'alias' => $alias, 'condition' => $condition];

        return $this;
    }

    public function leftJoin(string $table, string $alias, string $condition): static
    {
        $this->joins[] = ['type' => 'LEFT', 'table' => $table, 'alias' => $alias, 'condition' => $condition];

        return $this;
    }

    public function rightJoin(string $table, string $alias, string $condition): static
    {
        $this->joins[] = ['type' => 'RIGHT', 'table' => $table, 'alias' => $alias, 'condition' => $condition];

        return $this;
    }

    public function set(string $column, string $expression): static
    {
        $this->values[$column] = $expression;

        return $this;
    }

    public function setValue(string $column, string $expression): static
    {
        return $this->set($column, $expression);
    }

    public function values(array $values): static
    {
        foreach ($values as $column => $expression) {
            $this->values[(string) $column] = (string) $expression;
        }

        return $this;
    }

    public function where(string $condition): static
    {
        $this->where = $condition;

        return $this;
    }

    public function andWhere(string $condition): static
    {
        $this->where = $this->where === null ? $condition : '(' . $this->where . ') AND (' . $condition . ')';

        return $this;
    }

    public function orWhere(string $condition): static
    {
        $this->where = $this->where === null ? $condition : '(' . $this->where . ') OR (' . $condition . ')';

        return $this;
    }

    public function groupBy(string ...$columns): static
    {
        $this->groupBy = $columns;

        return $this;
    }

    public function addGroupBy(string ...$columns): static
    {
        array_push($this->groupBy, ...$columns);

        return $this;
    }

    public function having(string $condition): static
    {
        $this->having = $condition;

        return $this;
    }

    public function andHaving(string $condition): static
    {
        $this->having = $this->having === null ? $condition : '(' . $this->having . ') AND (' . $condition . ')';

        return $this;
    }

    public function orHaving(string $condition): static
    {
        $this->having = $this->having === null ? $condition : '(' . $this->having . ') OR (' . $condition . ')';

        return $this;
    }

    public function orderBy(string $sort, ?string $direction = null): static
    {
        $this->orderBy = [];

        return $this->addOrderBy($sort, $direction);
    }

    public function addOrderBy(string $sort, ?string $direction = null): static
    {
        $direction = $direction === null ? '' : (strtoupper($direction) === 'DESC' ? ' DESC' : ' ASC');
        $this->orderBy[] = $sort . $direction;

        return $this;
    }

    public function setMaxResults(?int $maxResults): static
    {
        $this->maxResults = $maxResults;

        return $this;
    }

    public function getMaxResults(): ?int
    {
        return $this->maxResults;
    }

    public function setFirstResult(?int $firstResult): static
    {
        $this->firstResult = $firstResult;

        return $this;
    }

    public function getFirstResult(): ?int
    {
        return $this->firstResult;
    }

    public function setParameter(string|int $name, mixed $value): static
    {
        $this->parameters[is_string($name) ? ltrim($name, ':') : $name] = $value;

        return $this;
    }

    public function setParameters(array $parameters): static
    {
        foreach ($parameters as $name => $value) {
            $this->setParameter($name, $value);
        }

        return $this;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getParameter(string|int $name): mixed
    {
        return $this->parameters[is_string($name) ? ltrim($name, ':') : $name] ?? null;
    }

    public function getSQL(): string
    {
        return match ($this->type) {
            self::INSERT => $this->getInsertSql(),
            self::UPDATE => $this->getUpdateSql(),
            self::DELETE => $this->getDeleteSql(),
            default => $this->getSelectSql(),
        };
    }

    public function executeQuery(): Result
    {
        return $this->connection->executeQuery($this->getSQL(), $this->parameters);
    }

    public function executeStatement(): int
    {
        return $this->connection->executeStatement($this->getSQL(), $this->parameters);
    }

    public function fetchAllAssociative(): array
    {
        return $this->executeQuery()->fetchAllAssociative();
    }

    public function fetchAssociative(): ?array
    {
        return $this->executeQuery()->fetchAssociative();
    }

    public function fetchOne(): mixed
    {
        return $this->executeQuery()->fetchOne();
    }

    public function fetchFirstColumn(): array
    {
        return $this->executeQuery()->fetchFirstColumn();
    }

    public function __toString(): string
    {
        return $this->getSQL();
    }

    protected function getSelectSql(): string
    {
        if ($this->from === null) {
            throw new OrmException('The query has no FROM clause: call from().');
        }

        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '') . ($this->select === [] ? '*' : implode(', ', $this->select))
            . ' FROM ' . $this->table($this->from['table'], $this->from['alias']);

        foreach ($this->joins as $join) {
            $sql .= ' ' . $join['type'] . ' JOIN ' . $this->table($join['table'], $join['alias']) . ' ON ' . $join['condition'];
        }

        if ($this->where !== null) {
            $sql .= ' WHERE ' . $this->where;
        }

        if ($this->groupBy !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }

        if ($this->having !== null) {
            $sql .= ' HAVING ' . $this->having;
        }

        if ($this->orderBy !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }

        return $sql . $this->getLimitSql();
    }

    protected function getLimitSql(): string
    {
        if ($this->maxResults === null && $this->firstResult === null) {
            return '';
        }

        if ($this->maxResults === null) {
            return $this->connection->getDriver()->getName() === 'mysql' ? ' LIMIT 18446744073709551615 OFFSET ' . (int) $this->firstResult : ($this->connection->getDriver()->getName() === 'sqlite' ? ' LIMIT -1 OFFSET ' . (int) $this->firstResult : ' OFFSET ' . (int) $this->firstResult);
        }

        return ' LIMIT ' . max(0, $this->maxResults) . ($this->firstResult ? ' OFFSET ' . (int) $this->firstResult : '');
    }

    protected function getInsertSql(): string
    {
        if ($this->values === []) {
            throw new OrmException('The INSERT query has no values: call values() or setValue().');
        }

        return 'INSERT INTO ' . $this->quote((string) $this->from['table'])
            . ' (' . implode(', ', array_map(fn (string $column): string => $this->quote($column), array_keys($this->values))) . ')'
            . ' VALUES (' . implode(', ', $this->values) . ')';
    }

    protected function getUpdateSql(): string
    {
        if ($this->values === []) {
            throw new OrmException('The UPDATE query has nothing to set: call set().');
        }

        $set = [];

        foreach ($this->values as $column => $expression) {
            $set[] = $this->quote($column) . ' = ' . $expression;
        }

        return 'UPDATE ' . $this->table((string) $this->from['table'], $this->from['alias']) . ' SET ' . implode(', ', $set) . ($this->where !== null ? ' WHERE ' . $this->where : '');
    }

    protected function getDeleteSql(): string
    {
        return 'DELETE FROM ' . $this->table((string) $this->from['table'], $this->from['alias']) . ($this->where !== null ? ' WHERE ' . $this->where : '');
    }

    protected function table(string $table, ?string $alias): string
    {
        return $this->quote($table) . ($alias !== null && $alias !== '' ? ' ' . $alias : '');
    }

    protected function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][\w.]*$/', $identifier) !== 1) {
            return $identifier;
        }

        return $this->connection->quoteIdentifier($identifier);
    }
}