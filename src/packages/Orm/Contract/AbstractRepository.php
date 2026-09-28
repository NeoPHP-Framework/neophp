<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Contract;

use NeoPHP\Package\Orm\Exception\OrmException;
use NeoPHP\Package\Orm\Metadata\ClassMetadata;
use NeoPHP\Package\Orm\Query\QueryBuilder;

abstract class AbstractRepository implements RepositoryInterface
{
    protected string $entityClass = '';

    public function __construct(protected OrmInterface $orm, ?string $entityClass = null)
    {
        if ($entityClass !== null) {
            $this->entityClass = $entityClass;
        }

        if ($this->entityClass === '') {
            throw new OrmException('The repository "{repository}" must define the $entityClass property.', 0, null, ['repository' => static::class]);
        }
    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function getOrm(): OrmInterface
    {
        return $this->orm;
    }

    public function getMetadata(): ClassMetadata
    {
        return $this->orm->getMetadata($this->entityClass);
    }

    public function find(mixed $id): ?object
    {
        return $this->orm->find($this->entityClass, $id);
    }

    public function findAll(array $orderBy = []): array
    {
        return $this->findBy([], $orderBy);
    }

    public function findBy(array $criteria, array $orderBy = [], ?int $limit = null, ?int $offset = null): array
    {
        return $this->createCriteriaQueryBuilder($criteria, $orderBy)->setMaxResults($limit)->setFirstResult($offset)->getResult();
    }

    public function findOneBy(array $criteria, array $orderBy = []): ?object
    {
        $result = $this->createCriteriaQueryBuilder($criteria, $orderBy)->setMaxResults(1)->getResult();

        return $result[0] ?? null;
    }

    public function count(array $criteria = []): int
    {
        return (int) $this->createCriteriaQueryBuilder($criteria)->select('COUNT(e.' . $this->getMetadata()->identifier . ')')->getSingleScalarResult();
    }

    public function createQueryBuilder(string $alias): QueryBuilder
    {
        return $this->orm->createQueryBuilder()->select($alias)->from($this->entityClass, $alias);
    }

    public function save(object $entity, bool $flush = false): void
    {
        $this->orm->persist($entity);

        if ($flush) {
            $this->orm->flush();
        }
    }

    public function delete(object $entity, bool $flush = false): void
    {
        $this->orm->remove($entity);

        if ($flush) {
            $this->orm->flush();
        }
    }

    protected function createCriteriaQueryBuilder(array $criteria, array $orderBy = []): QueryBuilder
    {
        $builder = $this->createQueryBuilder('e');
        $index = 0;

        foreach ($criteria as $field => $value) {
            $this->assertKnownField($field);
            $parameter = 'p' . $index++;

            if ($value === null) {
                $builder->andWhere('e.' . $field . ' IS NULL');
            } elseif (is_array($value) || $value instanceof CollectionInterface) {
                $builder->andWhere('e.' . $field . ' IN (:' . $parameter . ')')->setParameter($parameter, $value);
            } else {
                $builder->andWhere('e.' . $field . ' = :' . $parameter)->setParameter($parameter, $value);
            }
        }

        foreach ($orderBy as $field => $direction) {
            $this->assertKnownField($field);
            $builder->addOrderBy('e.' . $field, (string) $direction);
        }

        return $builder;
    }

    protected function assertKnownField(int|string $field): void
    {
        $metadata = $this->getMetadata();

        if (!is_string($field) || (!$metadata->hasField($field) && !$metadata->hasAssociation($field))) {
            throw new OrmException('The entity "{class}" has no field or association "{field}".', 0, null, ['class' => $this->entityClass, 'field' => (string) $field]);
        }
    }
}