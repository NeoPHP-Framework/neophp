<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Contract;

use NeoPHP\Package\Orm\Query\QueryBuilder;

interface RepositoryInterface
{
    public function getEntityClass(): string;

    public function find(mixed $id): ?object;

    public function findAll(array $orderBy = []): array;

    public function findBy(array $criteria, array $orderBy = [], ?int $limit = null, ?int $offset = null): array;

    public function findOneBy(array $criteria, array $orderBy = []): ?object;

    public function count(array $criteria = []): int;

    public function createQueryBuilder(string $alias): QueryBuilder;

    public function save(object $entity, bool $flush = false): void;

    public function delete(object $entity, bool $flush = false): void;
}