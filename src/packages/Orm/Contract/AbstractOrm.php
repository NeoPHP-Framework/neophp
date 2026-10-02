<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Contract;

use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Database\Contract\ConnectionInterface;
use NeoPHP\Component\Event\Contract\EventDispatcherInterface;
use NeoPHP\Package\Orm\Exception\OrmException;
use NeoPHP\Package\Orm\Metadata\ClassMetadata;
use NeoPHP\Package\Orm\Metadata\MetadataFactory;
use NeoPHP\Package\Orm\Platform\MysqlPlatform;
use NeoPHP\Package\Orm\Platform\PgsqlPlatform;
use NeoPHP\Package\Orm\Platform\SqlitePlatform;
use NeoPHP\Package\Orm\Proxy\ProxyFactory;
use NeoPHP\Package\Orm\Query\QueryBuilder;
use NeoPHP\Package\Orm\Query\SqlQueryBuilder;
use NeoPHP\Package\Orm\Repository\EntityRepository;
use NeoPHP\Package\Orm\UnitOfWork\UnitOfWork;
use Throwable;

abstract class AbstractOrm implements EntityManagerInterface
{
    protected ConnectionInterface $connection;

    protected MetadataFactory $metadataFactory;

    protected ProxyFactory $proxyFactory;

    protected ?EventDispatcherInterface $eventDispatcher = null;

    protected ?ContainerInterface $container = null;

    protected ?UnitOfWork $unitOfWork = null;

    protected ?PlatformInterface $platform = null;

    protected array $repositories = [];

    protected string $repositoryNamespace = 'App\\Repository';

    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    public function getMetadataFactory(): MetadataFactory
    {
        return $this->metadataFactory;
    }

    public function getMetadata(string|object $class): ClassMetadata
    {
        return $this->metadataFactory->getMetadata($class);
    }

    public function getUnitOfWork(): UnitOfWork
    {
        return $this->unitOfWork ??= new UnitOfWork($this);
    }

    public function getProxyFactory(): ProxyFactory
    {
        return $this->proxyFactory;
    }

    public function getPlatform(): PlatformInterface
    {
        return $this->platform ??= match ($this->connection->getDriver()->getName()) {
            'mysql' => new MysqlPlatform(),
            'pgsql' => new PgsqlPlatform(),
            'sqlite' => new SqlitePlatform(),
            default => throw new OrmException('The ORM does not support the "{driver}" driver.', 0, null, ['driver' => $this->connection->getDriver()->getName()]),
        };
    }

    public function getEventDispatcher(): ?EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    public function persist(object $entity): void
    {
        $this->getUnitOfWork()->persist($entity);
    }

    public function remove(object $entity): void
    {
        $this->getUnitOfWork()->remove($entity);
    }

    public function flush(): void
    {
        $this->getUnitOfWork()->flush();
    }

    public function find(string $class, mixed $id): ?object
    {
        if ($id === null || $id === '') {
            return null;
        }

        $metadata = $this->getMetadata($class);

        return $this->getUnitOfWork()->find($metadata, $this->normalizeId($metadata, $id));
    }

    public function getReference(string $class, mixed $id): object
    {
        $metadata = $this->getMetadata($class);

        return $this->getUnitOfWork()->getReference($metadata, $this->normalizeId($metadata, $id));
    }

    public function getRepository(string $class): RepositoryInterface
    {
        $metadata = $this->getMetadata($class);

        if (isset($this->repositories[$metadata->name])) {
            return $this->repositories[$metadata->name];
        }

        $repository = $metadata->repository;

        if ($repository === null) {
            $candidate = rtrim($this->repositoryNamespace, '\\') . '\\' . $metadata->reflection->getShortName() . 'Repository';
            $repository = class_exists($candidate) && is_subclass_of($candidate, RepositoryInterface::class) ? $candidate : null;
        }

        if ($repository === null) {
            return $this->repositories[$metadata->name] = new EntityRepository($this, $metadata->name);
        }

        if (!is_subclass_of($repository, RepositoryInterface::class)) {
            throw new OrmException('The repository "{repository}" of the entity "{class}" must implement {interface}.', 0, null, [
                'repository' => $repository,
                'class' => $metadata->name,
                'interface' => RepositoryInterface::class,
            ]);
        }

        $instance = $this->container !== null ? $this->container->get($repository) : new $repository($this);

        return $this->repositories[$metadata->name] = $instance;
    }

    public function createQueryBuilder(): QueryBuilder
    {
        return new QueryBuilder($this);
    }

    public function createSqlQueryBuilder(): SqlQueryBuilder
    {
        return new SqlQueryBuilder($this->connection);
    }

    public function contains(object $entity): bool
    {
        return $this->getUnitOfWork()->contains($entity);
    }

    public function detach(object $entity): void
    {
        $this->getUnitOfWork()->detach($entity);
    }

    public function refresh(object $entity): void
    {
        $this->getUnitOfWork()->refresh($entity);
    }

    public function clear(): void
    {
        $this->getUnitOfWork()->clear();
    }

    public function transactional(callable $callback): mixed
    {
        $this->connection->beginTransaction();

        try {
            $result = $callback($this);
            $this->flush();
            $this->connection->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    protected function normalizeId(ClassMetadata $metadata, mixed $id): mixed
    {
        if (is_object($id) && $this->metadataFactory->isEntity($id)) {
            $id = $this->getMetadata($id)->getIdentifierValue($id);
        }

        return in_array($metadata->getIdentifierType(), ['integer', 'smallint', 'bigint'], true) && is_numeric($id) ? (int) $id : $id;
    }
}