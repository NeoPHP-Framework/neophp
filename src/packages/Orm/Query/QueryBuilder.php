<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Query;

use NeoPHP\Package\Orm\Collection\PersistentCollection;
use NeoPHP\Package\Orm\Contract\CollectionInterface;
use NeoPHP\Package\Orm\Exception\NonUniqueResultException;
use NeoPHP\Package\Orm\Exception\NoResultException;
use NeoPHP\Package\Orm\Exception\OrmException;
use NeoPHP\Package\Orm\Metadata\ClassMetadata;
use NeoPHP\Package\Orm\OrmManagerInterface;
use NeoPHP\Package\Orm\Type\Type;

class QueryBuilder
{
    public const TOKENS = '/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|(?<![\w:.$])([A-Za-z_]\w*)\.([A-Za-z_]\w*)/s';

    protected array $select = [];

    protected bool $distinct = false;

    protected ?array $from = null;

    protected array $joins = [];

    protected array $aliases = [];

    protected array $where = [];

    protected array $groupBy = [];

    protected array $having = [];

    protected array $orderBy = [];

    protected ?int $maxResults = null;

    protected ?int $firstResult = null;

    protected array $parameters = [];

    protected array $columnMap = [];

    protected array $scalars = [];

    public function __construct(protected OrmManagerInterface $orm)
    {
    }

    public function select(string ...$select): static
    {
        $this->select = $select;

        return $this;
    }

    public function addSelect(string ...$select): static
    {
        array_push($this->select, ...$select);

        return $this;
    }

    public function distinct(bool $distinct = true): static
    {
        $this->distinct = $distinct;

        return $this;
    }

    public function from(string $class, string $alias): static
    {
        $metadata = $this->orm->getMetadata($class);
        $this->from = ['class' => $metadata->name, 'alias' => $alias];
        $this->aliases[$alias] = ['class' => $metadata->name, 'parent' => null, 'field' => null];

        return $this;
    }

    public function getRootAlias(): string
    {
        return (string) ($this->from['alias'] ?? '');
    }

    public function getRootEntity(): string
    {
        return (string) ($this->from['class'] ?? '');
    }

    public function join(string $join, string $alias, ?string $condition = null): static
    {
        return $this->addJoin('INNER', $join, $alias, $condition);
    }

    public function innerJoin(string $join, string $alias, ?string $condition = null): static
    {
        return $this->addJoin('INNER', $join, $alias, $condition);
    }

    public function leftJoin(string $join, string $alias, ?string $condition = null): static
    {
        return $this->addJoin('LEFT', $join, $alias, $condition);
    }

    public function where(string $condition): static
    {
        $this->where = [$condition];

        return $this;
    }

    public function andWhere(string $condition): static
    {
        $this->where[] = $condition;

        return $this;
    }

    public function orWhere(string $condition): static
    {
        $this->where = $this->where === [] ? [$condition] : ['(' . implode(') AND (', $this->where) . ') OR (' . $condition . ')'];

        return $this;
    }

    public function groupBy(string ...$groupBy): static
    {
        $this->groupBy = $groupBy;

        return $this;
    }

    public function addGroupBy(string ...$groupBy): static
    {
        array_push($this->groupBy, ...$groupBy);

        return $this;
    }

    public function having(string $condition): static
    {
        $this->having = [$condition];

        return $this;
    }

    public function andHaving(string $condition): static
    {
        $this->having[] = $condition;

        return $this;
    }

    public function orderBy(string $sort, ?string $direction = null): static
    {
        $this->orderBy = [[$sort, $direction]];

        return $this;
    }

    public function addOrderBy(string $sort, ?string $direction = null): static
    {
        $this->orderBy[] = [$sort, $direction];

        return $this;
    }

    public function setMaxResults(?int $maxResults): static
    {
        $this->maxResults = $maxResults;

        return $this;
    }

    public function setFirstResult(?int $firstResult): static
    {
        $this->firstResult = $firstResult;

        return $this;
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

    public function getSQL(): string
    {
        return $this->build()->getSQL();
    }

    public function getSqlQueryBuilder(): SqlQueryBuilder
    {
        return $this->build();
    }

    public function getResult(): array
    {
        $rows = $this->build()->executeQuery()->fetchAllAssociative();

        if ($this->getEntityAliases() === []) {
            return $rows;
        }

        return $this->hydrateRows($rows);
    }

    public function getOneOrNullResult(): mixed
    {
        $result = $this->getResult();

        if (count($result) > 1) {
            throw new NonUniqueResultException('The query returned {count} results, one or none was expected.', 0, null, ['count' => count($result)]);
        }

        return $result === [] ? null : reset($result);
    }

    public function getSingleResult(): mixed
    {
        return $this->getOneOrNullResult() ?? throw new NoResultException('The query returned no result, one was expected.');
    }

    public function getSingleScalarResult(): mixed
    {
        $row = $this->build()->executeQuery()->fetchNumeric();

        if ($row === null) {
            throw new NoResultException('The query returned no result, one was expected.');
        }

        return $row[0];
    }

    public function getScalarResult(): array
    {
        return $this->build()->executeQuery()->fetchAllAssociative();
    }

    public function getArrayResult(): array
    {
        $rows = $this->build()->executeQuery()->fetchAllAssociative();
        $root = $this->getRootAlias();

        if (!in_array($root, $this->getEntityAliases(), true)) {
            return $rows;
        }

        $metadata = $this->orm->getMetadata($this->aliases[$root]['class']);
        $result = [];

        foreach ($rows as $row) {
            $data = $this->extract($root, $row);
            $id = $data[$metadata->getIdentifierColumn()] ?? null;

            if ($id === null || isset($result[(string) $id])) {
                continue;
            }

            $item = [];

            foreach ($metadata->fields as $field => $mapping) {
                $item[$field] = Type::toPhp($data[$mapping['column']] ?? null, $mapping['type'], $mapping['enumType'], $mapping['scale']);
            }

            foreach ($metadata->getOwningToOneAssociations() as $field => $association) {
                $item[$field] = $data[$association['joinColumn']] ?? null;
            }

            $result[(string) $id] = $item;
        }

        return array_values($result);
    }

    protected function addJoin(string $type, string $join, string $alias, ?string $condition): static
    {
        if (isset($this->aliases[$alias])) {
            throw new OrmException('The alias "{alias}" is already used in the query.', 0, null, ['alias' => $alias]);
        }

        if (preg_match('/^([A-Za-z_]\w*)\.([A-Za-z_]\w*)$/', $join, $m) === 1 && isset($this->aliases[$m[1]])) {
            $parent = $this->orm->getMetadata($this->aliases[$m[1]]['class']);
            $association = $parent->getAssociation($m[2]);
            $this->aliases[$alias] = ['class' => $association['target'], 'parent' => $m[1], 'field' => $m[2]];
            $this->joins[] = ['type' => $type, 'alias' => $alias, 'parent' => $m[1], 'association' => $association, 'condition' => $condition];

            return $this;
        }

        $metadata = $this->orm->getMetadata($join);

        if ($condition === null) {
            throw new OrmException('Joining the entity "{class}" requires a condition.', 0, null, ['class' => $join]);
        }

        $this->aliases[$alias] = ['class' => $metadata->name, 'parent' => null, 'field' => null];
        $this->joins[] = ['type' => $type, 'alias' => $alias, 'parent' => null, 'association' => null, 'class' => $metadata->name, 'condition' => $condition];

        return $this;
    }

    protected function build(): SqlQueryBuilder
    {
        if ($this->from === null) {
            throw new OrmException('The query has no FROM clause: call from().');
        }

        $connection = $this->orm->getConnection();
        $sql = new SqlQueryBuilder($connection);
        $root = $this->orm->getMetadata($this->from['class']);
        $sql->from($root->table, $this->from['alias'])->distinct($this->distinct);
        $this->columnMap = [];
        $this->scalars = [];
        $select = $this->select === [] ? [$this->from['alias']] : $this->select;
        $columns = [];

        foreach ($select as $item) {
            foreach (array_map('trim', $this->splitSelect($item)) as $part) {
                if (isset($this->aliases[$part])) {
                    array_push($columns, ...$this->selectEntity($part));
                    continue;
                }

                $name = null;

                if (preg_match('/^(.*?)\s+AS\s+(\w+)$/is', $part, $m) === 1) {
                    [$part, $name] = [$m[1], $m[2]];
                }

                if ($name === null && preg_match('/^([A-Za-z_]\w*)\.([A-Za-z_]\w*)$/', $part, $m) === 1 && isset($this->aliases[$m[1]])) {
                    $name = $m[2];
                }

                $name ??= 'sclr_' . count($this->scalars);
                $this->scalars[] = $name;
                $columns[] = $this->translate($part) . ' AS ' . $name;
            }
        }

        $sql->select(...$columns);

        foreach ($this->joins as $join) {
            $this->buildJoin($sql, $join);
        }

        if ($this->where !== []) {
            $sql->where(count($this->where) === 1 ? $this->translate($this->where[0]) : '(' . implode(') AND (', array_map(fn (string $condition): string => $this->translate($condition), $this->where)) . ')');
        }

        if ($this->groupBy !== []) {
            $sql->groupBy(...array_map(fn (string $group): string => $this->translate($group), $this->groupBy));
        }

        if ($this->having !== []) {
            $sql->having('(' . implode(') AND (', array_map(fn (string $condition): string => $this->translate($condition), $this->having)) . ')');
        }

        foreach ($this->orderBy as [$sort, $direction]) {
            $sql->addOrderBy($this->translate($sort), $direction);
        }

        $sql->setMaxResults($this->maxResults)->setFirstResult($this->firstResult);

        foreach ($this->parameters as $name => $value) {
            $sql->setParameter($name, $this->convertParameter($value));
        }

        return $sql;
    }

    protected function selectEntity(string $alias): array
    {
        $metadata = $this->orm->getMetadata($this->aliases[$alias]['class']);
        $connection = $this->orm->getConnection();
        $columns = [];
        $names = array_values(array_map(static fn (array $field): string => $field['column'], $metadata->fields));

        foreach ($metadata->getOwningToOneAssociations() as $association) {
            $names[] = $association['joinColumn'];
        }

        foreach ($names as $column) {
            $sqlAlias = $alias . '_' . count($this->columnMap);
            $this->columnMap[$sqlAlias] = [$alias, $column];
            $columns[] = $alias . '.' . $connection->quoteIdentifier($column) . ' AS ' . $sqlAlias;
        }

        return $columns;
    }

    protected function buildJoin(SqlQueryBuilder $sql, array $join): void
    {
        $connection = $this->orm->getConnection();
        $method = $join['type'] === 'LEFT' ? 'leftJoin' : 'innerJoin';
        $alias = $join['alias'];
        $extra = $join['condition'] !== null ? ' AND (' . $this->translate($join['condition']) . ')' : '';

        if ($join['association'] === null) {
            $sql->{$method}($this->orm->getMetadata($join['class'])->table, $alias, $this->translate($join['condition']));

            return;
        }

        $association = $join['association'];
        $parent = $join['parent'];
        $parentMetadata = $this->orm->getMetadata($this->aliases[$parent]['class']);
        $target = $this->orm->getMetadata($association['target']);
        $q = static fn (string $identifier): string => $connection->quoteIdentifier($identifier);

        if ($association['type'] === ClassMetadata::MANY_TO_MANY) {
            $joinAlias = $alias . '_j';
            $sql->{$method}($association['joinTable'], $joinAlias, $joinAlias . '.' . $q($association['joinColumn']) . ' = ' . $parent . '.' . $q($parentMetadata->getIdentifierColumn()));
            $sql->{$method}($target->table, $alias, $alias . '.' . $q($target->getIdentifierColumn()) . ' = ' . $joinAlias . '.' . $q($association['inverseJoinColumn']) . $extra);

            return;
        }

        if ($association['owning']) {
            $sql->{$method}($target->table, $alias, $alias . '.' . $q($target->getIdentifierColumn()) . ' = ' . $parent . '.' . $q($association['joinColumn']) . $extra);

            return;
        }

        $sql->{$method}($target->table, $alias, $alias . '.' . $q($association['joinColumn']) . ' = ' . $parent . '.' . $q($parentMetadata->getIdentifierColumn()) . $extra);
    }

    protected function translate(string $expression): string
    {
        $connection = $this->orm->getConnection();

        return (string) preg_replace_callback(self::TOKENS, function (array $m) use ($connection): string {
            if (!isset($m[1]) || !isset($this->aliases[$m[1]])) {
                return $m[0];
            }

            $metadata = $this->orm->getMetadata($this->aliases[$m[1]]['class']);

            if (!$metadata->hasField($m[2]) && !$metadata->hasAssociation($m[2])) {
                throw new OrmException('The entity "{class}" (alias "{alias}") has no field "{field}".', 0, null, ['class' => $metadata->name, 'alias' => $m[1], 'field' => $m[2]]);
            }

            if ($metadata->hasAssociation($m[2]) && !isset($metadata->getOwningToOneAssociations()[$m[2]])) {
                throw new OrmException('The association "{alias}.{field}" cannot be used in an expression: join it instead.', 0, null, ['alias' => $m[1], 'field' => $m[2]]);
            }

            return $m[1] . '.' . $connection->quoteIdentifier($metadata->getColumnName($m[2]));
        }, $expression);
    }

    protected function splitSelect(string $select): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (str_split($select) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return array_filter($parts, static fn (string $part): bool => trim($part) !== '');
    }

    protected function convertParameter(mixed $value): mixed
    {
        if ($value instanceof CollectionInterface) {
            $value = $value->getValues();
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->convertParameter($item), $value);
        }

        if (is_object($value) && $this->orm->getMetadataFactory()->isEntity($value)) {
            $metadata = $this->orm->getMetadata($value);

            return Type::toDatabase($metadata->getIdentifierValue($value), $metadata->getIdentifierType());
        }

        return $value;
    }

    protected function getEntityAliases(): array
    {
        $aliases = [];

        foreach (array_unique(array_column($this->columnMap, 0)) as $alias) {
            $aliases[] = $alias;
        }

        return $aliases;
    }

    protected function extract(string $alias, array $row): array
    {
        $data = [];

        foreach ($this->columnMap as $sqlAlias => [$owner, $column]) {
            if ($owner === $alias && array_key_exists($sqlAlias, $row)) {
                $data[$column] = $row[$sqlAlias];
            }
        }

        return $data;
    }

    protected function hydrateRows(array $rows): array
    {
        $hydrator = $this->orm->getUnitOfWork()->getHydrator();
        $aliases = $this->getEntityAliases();
        $root = $this->getRootAlias();
        $mixed = $this->scalars !== [];
        $results = [];
        $collections = [];
        $order = $this->hydrationOrder($aliases);

        foreach ($rows as $row) {
            $entities = [];

            foreach ($order as $alias) {
                $metadata = $this->orm->getMetadata($this->aliases[$alias]['class']);
                $data = $this->extract($alias, $row);
                $entities[$alias] = ($data[$metadata->getIdentifierColumn()] ?? null) === null ? null : $hydrator->hydrate($metadata, $data);
            }

            foreach ($aliases as $alias) {
                $parent = $this->aliases[$alias]['parent'];

                if ($parent === null || !isset($entities[$parent])) {
                    continue;
                }

                $parentMetadata = $this->orm->getMetadata($this->aliases[$parent]['class']);
                $association = $parentMetadata->getAssociation((string) $this->aliases[$alias]['field']);

                if (!in_array($association['type'], ClassMetadata::TO_MANY, true)) {
                    continue;
                }

                $key = spl_object_id($entities[$parent]) . '.' . $association['field'];
                $collections[$key] ??= [$entities[$parent], $association['field'], $parentMetadata, []];

                if ($entities[$alias] !== null) {
                    $collections[$key][3][spl_object_id($entities[$alias])] = $entities[$alias];
                }
            }

            $entity = $entities[$root] ?? null;

            if ($mixed) {
                $result = [$entity];

                foreach ($this->scalars as $name) {
                    $result[$name] = $row[$name] ?? null;
                }

                $results[] = $result;
            } elseif ($entity !== null) {
                $results[spl_object_id($entity)] = $entity;
            }
        }

        foreach ($collections as [$owner, $field, $metadata, $elements]) {
            $collection = $metadata->getValue($owner, $field);

            if ($collection instanceof PersistentCollection && !$collection->isInitialized()) {
                $collection->hydrate(array_values($elements));
            }
        }

        return array_values($results);
    }

    protected function hydrationOrder(array $aliases): array
    {
        $order = [];
        $visit = function (string $alias) use (&$visit, &$order, $aliases): void {
            if (in_array($alias, $order, true)) {
                return;
            }

            $children = array_filter($aliases, fn (string $child): bool => $this->aliases[$child]['parent'] === $alias);
            $before = [];
            $after = [];

            foreach ($children as $child) {
                $association = $this->orm->getMetadata($this->aliases[$alias]['class'])->getAssociation((string) $this->aliases[$child]['field']);

                if (in_array($association['type'], ClassMetadata::TO_ONE, true) && $association['owning']) {
                    $before[] = $child;
                } else {
                    $after[] = $child;
                }
            }

            foreach ($before as $child) {
                $visit($child);
            }

            $order[] = $alias;

            foreach ($after as $child) {
                $visit($child);
            }
        };

        $root = $this->getRootAlias();

        if (in_array($root, $aliases, true)) {
            $visit($root);
        }

        foreach ($aliases as $alias) {
            $visit($alias);
        }

        return $order;
    }
}