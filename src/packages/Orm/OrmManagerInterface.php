<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm;

use NeoPHP\Component\Database\Contract\ConnectionInterface;
use NeoPHP\Component\Event\EventManagerInterface;
use NeoPHP\Package\Orm\Contract\PlatformInterface;
use NeoPHP\Package\Orm\Contract\RepositoryInterface;
use NeoPHP\Package\Orm\Metadata\ClassMetadata;
use NeoPHP\Package\Orm\Metadata\MetadataFactory;
use NeoPHP\Package\Orm\Proxy\ProxyFactory;
use NeoPHP\Package\Orm\Query\QueryBuilder;
use NeoPHP\Package\Orm\Query\SqlQueryBuilder;
use NeoPHP\Package\Orm\UnitOfWork\UnitOfWork;

interface OrmManagerInterface
{
    public function getConnection(): ConnectionInterface;

    public function getMetadataFactory(): MetadataFactory;

    public function getMetadata(string|object $class): ClassMetadata;

    public function getUnitOfWork(): UnitOfWork;

    public function getProxyFactory(): ProxyFactory;

    public function getPlatform(): PlatformInterface;

    public function getEventDispatcher(): ?EventManagerInterface;

    public function persist(object $entity): void;

    public function remove(object $entity): void;

    public function flush(): void;

    public function find(string $class, mixed $id): ?object;

    public function getReference(string $class, mixed $id): object;

    public function getRepository(string $class): RepositoryInterface;

    public function createQueryBuilder(): QueryBuilder;

    public function createSqlQueryBuilder(): SqlQueryBuilder;

    public function contains(object $entity): bool;

    public function detach(object $entity): void;

    public function refresh(object $entity): void;

    public function clear(): void;

    public function transactional(callable $callback): mixed;
}