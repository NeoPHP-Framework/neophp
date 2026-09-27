<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Pagination\Adapter;

use Closure;
use NeoPHP\Component\Api\Pagination\Contract\AdapterInterface;
use NeoPHP\Package\Orm\Query\QueryBuilder;

class QueryBuilderAdapter implements AdapterInterface
{
    protected ?int $count = null;

    public function __construct(protected QueryBuilder $queryBuilder, protected ?Closure $counter = null)
    {
    }

    public function count(): int
    {
        if ($this->count !== null) {
            return $this->count;
        }

        if ($this->counter !== null) {
            return $this->count = (int) ($this->counter)(clone $this->queryBuilder);
        }

        $query = (clone $this->queryBuilder)->setMaxResults(null)->setFirstResult(null);
        $sql = $query->getSqlQueryBuilder();

        return $this->count = (int) $sql->getConnection()->fetchOne('SELECT COUNT(*) FROM (' . $sql->getSQL() . ') neo_paginator_count', $sql->getParameters());
    }

    public function slice(int $offset, int $length): iterable
    {
        return (clone $this->queryBuilder)->setFirstResult($offset)->setMaxResults($length)->getResult();
    }

    public function getQueryBuilder(): QueryBuilder
    {
        return $this->queryBuilder;
    }
}