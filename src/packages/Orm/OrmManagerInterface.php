<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm;

use NeoPHP\Component\Database\Contract\ConnectionInterface;
use NeoPHP\Component\Database\Exception\QueryException;
use NeoPHP\Component\Event\EventManagerInterface;
use NeoPHP\Package\Orm\Contract\PlatformInterface;
use NeoPHP\Package\Orm\Contract\RepositoryInterface;
use NeoPHP\Package\Orm\Exception\EntityNotFoundException;
use NeoPHP\Package\Orm\Exception\MappingException;
use NeoPHP\Package\Orm\Exception\OrmException;
use NeoPHP\Package\Orm\Metadata\ClassMetadata;
use NeoPHP\Package\Orm\Metadata\MetadataFactory;
use NeoPHP\Package\Orm\Proxy\ProxyFactory;
use NeoPHP\Package\Orm\Query\QueryBuilder;
use NeoPHP\Package\Orm\Query\SqlQueryBuilder;
use NeoPHP\Package\Orm\UnitOfWork\UnitOfWork;

interface OrmManagerInterface
{
    /**
     * Returns the database connection of the ORM.
     *
     * @return ConnectionInterface The connection
     */
    public function getConnection(): ConnectionInterface;

    /**
     * Returns the factory reading the mapping attributes of the entities.
     *
     * @return MetadataFactory The metadata factory
     */
    public function getMetadataFactory(): MetadataFactory;

    /**
     * Returns the mapping of an entity.
     *
     * @param string|object $class Class of the entity, or an entity or a proxy
     * @return ClassMetadata The mapping
     * @throws MappingException When the class does not exist, is not an entity, is abstract or has no identifier
     */
    public function getMetadata(string|object $class): ClassMetadata;

    /**
     * Returns the unit of work tracking the managed entities and their changes.
     *
     * @return UnitOfWork The unit of work
     */
    public function getUnitOfWork(): UnitOfWork;

    /**
     * Returns the factory of the lazy proxies of the relations.
     *
     * @return ProxyFactory The proxy factory
     */
    public function getProxyFactory(): ProxyFactory;

    /**
     * Returns the SQL platform of the connection.
     *
     * @return PlatformInterface The MySQL, PostgreSQL or SQLite platform
     * @throws OrmException When the driver of the connection is not supported
     */
    public function getPlatform(): PlatformInterface;

    /**
     * Returns the event manager dispatching the lifecycle events.
     *
     * @return EventManagerInterface|null The event manager, or null without the Event component
     */
    public function getEventDispatcher(): ?EventManagerInterface;

    /**
     * Manages a new entity: it is inserted on the next flush(), with the related entities of the persist cascades.
     *
     * @param object $entity The entity
     * @return void
     * @throws MappingException When the class is not an entity
     * @throws OrmException When the entity was detached
     */
    public function persist(object $entity): void;

    /**
     * Schedules the deletion of a managed entity, with the related entities of the remove cascades.
     *
     * @param object $entity The entity
     * @return void
     * @throws MappingException When the class is not an entity
     * @throws OrmException When the entity is not managed
     */
    public function remove(object $entity): void;

    /**
     * Writes the changes of every managed entity in one transaction: inserts, updates of the changed columns, join tables and deletes.
     *
     * @return void
     * @throws OrmException When a new entity is found through a relation without persist cascade, an entity has no identifier or the inserts cannot be ordered
     * @throws QueryException When a SQL statement fails
     */
    public function flush(): void;

    /**
     * Returns an entity by its identifier, from the identity map or the database.
     *
     * @param string $class Class of the entity
     * @param mixed $id The identifier, or an entity for a relation used as identifier
     * @return object|null The entity, or null when it does not exist or the identifier is empty
     * @throws MappingException When the class is not an entity
     * @throws QueryException When the query fails
     */
    public function find(string $class, mixed $id): ?object;

    /**
     * Returns a proxy of an entity without querying the database; it is loaded on its first method call.
     *
     * @param string $class Class of the entity
     * @param mixed $id The identifier
     * @return object The managed entity, or a proxy
     * @throws MappingException When the class is not an entity
     */
    public function getReference(string $class, mixed $id): object;

    /**
     * Returns the repository of an entity: its repository option, the App\Repository\<Entity>Repository class, or a generic EntityRepository.
     *
     * @param string $class Class of the entity
     * @return RepositoryInterface The repository
     * @throws MappingException When the class is not an entity
     * @throws OrmException When the repository does not implement RepositoryInterface
     */
    public function getRepository(string $class): RepositoryInterface;

    /**
     * Creates a query builder working on entities, properties and relations.
     *
     * @return QueryBuilder The query builder
     */
    public function createQueryBuilder(): QueryBuilder;

    /**
     * Creates a query builder working on tables and columns.
     *
     * @return SqlQueryBuilder The SQL query builder
     */
    public function createSqlQueryBuilder(): SqlQueryBuilder;

    /**
     * Tells whether an entity is managed by the unit of work.
     *
     * @param object $entity The entity
     * @return bool True when the entity is managed
     */
    public function contains(object $entity): bool;

    /**
     * Stops managing an entity: its changes are no longer written.
     *
     * @param object $entity The entity
     * @return void
     */
    public function detach(object $entity): void;

    /**
     * Reloads the values of a managed entity from the database, losing its unsaved changes.
     *
     * @param object $entity The entity
     * @return void
     * @throws EntityNotFoundException When the entity no longer exists in the database
     * @throws QueryException When the query fails
     */
    public function refresh(object $entity): void;

    /**
     * Stops managing every entity; to call after a failed flush().
     *
     * @return void
     */
    public function clear(): void;

    /**
     * Runs a callback and flushes in a transaction, rolled back when an exception is thrown.
     *
     * @param callable(OrmManagerInterface): mixed $callback The callback, receiving the ORM
     * @return mixed The value returned by the callback
     * @throws OrmException When the flush fails
     * @throws QueryException When a SQL statement fails
     */
    public function transactional(callable $callback): mixed;
}