<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\UnitOfWork;

use NeoPHP\Package\Orm\Collection\PersistentCollection;
use NeoPHP\Package\Orm\Contract\ProxyInterface;
use NeoPHP\Package\Orm\Event\PostLoadEvent;
use NeoPHP\Package\Orm\Metadata\ClassMetadata;
use NeoPHP\Package\Orm\OrmManagerInterface;
use NeoPHP\Package\Orm\Type\Type;

class Hydrator
{
    public function __construct(protected OrmManagerInterface $orm, protected UnitOfWork $unitOfWork)
    {
    }

    public function hydrate(ClassMetadata $metadata, array $row, ?object $entity = null, bool $refresh = false): object
    {
        $idColumn = $metadata->getIdentifierColumn();
        $id = Type::toPhp($row[$idColumn] ?? null, $metadata->getIdentifierType());

        if ($entity === null) {
            $existing = $this->unitOfWork->tryGetById($metadata->name, $id);

            if ($existing !== null) {
                if (!$refresh && !($existing instanceof ProxyInterface && !$existing->__neoIsInitialized())) {
                    return $existing;
                }

                $entity = $existing;
            } else {
                $entity = $metadata->newInstance();
            }
        }

        if ($entity instanceof ProxyInterface) {
            $entity->__neoSetInitialized(true);
        }

        $this->unitOfWork->registerManaged($entity, $metadata, $id);

        foreach ($metadata->fields as $field => $mapping) {
            if (array_key_exists($mapping['column'], $row)) {
                $metadata->setValue($entity, $field, Type::toPhp($row[$mapping['column']], $mapping['type'], $mapping['enumType'], $mapping['scale']));
            }
        }

        foreach ($metadata->associations as $field => $association) {
            $this->hydrateAssociation($metadata, $entity, $field, $association, $row, $refresh);
        }

        $this->unitOfWork->setOriginalData($entity, $this->unitOfWork->getEntityData($metadata, $entity));
        $this->unitOfWork->invokeLifecycle($metadata, $entity, 'postLoad', new PostLoadEvent($entity, $this->orm));

        return $entity;
    }

    protected function hydrateAssociation(ClassMetadata $metadata, object $entity, string $field, array $association, array $row, bool $refresh): void
    {
        $target = $this->orm->getMetadata($association['target']);

        if (in_array($association['type'], ClassMetadata::TO_ONE, true) && $association['owning']) {
            if (!array_key_exists($association['joinColumn'], $row)) {
                return;
            }

            $value = $row[$association['joinColumn']];

            if ($value === null) {
                $metadata->setValue($entity, $field, null);

                return;
            }

            $id = Type::toPhp($value, $target->getIdentifierType());
            $related = $association['fetch'] === 'eager' ? $this->unitOfWork->find($target, $id) : $this->unitOfWork->getReference($target, $id);
            $metadata->setValue($entity, $field, $related);

            return;
        }

        if ($association['type'] === ClassMetadata::ONE_TO_ONE) {
            $connection = $this->orm->getConnection();
            $sql = sprintf('SELECT * FROM %s WHERE %s = ?', $connection->quoteIdentifier($target->table), $connection->quoteIdentifier($association['joinColumn']));
            $related = $connection->fetchAssociative($sql, [Type::toDatabase($metadata->getIdentifierValue($entity), $metadata->getIdentifierType())]);
            $metadata->setValue($entity, $field, $related === null ? null : $this->hydrate($target, $related));

            return;
        }

        $current = $metadata->getValue($entity, $field);

        if (!$refresh && $current instanceof PersistentCollection && $current->getOwner() === $entity) {
            return;
        }

        $metadata->setValue($entity, $field, new PersistentCollection($entity, $association, fn (PersistentCollection $collection): array => $this->unitOfWork->loadCollection($collection)));
    }
}