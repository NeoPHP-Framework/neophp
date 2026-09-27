<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\UnitOfWork;

use NeoPHP\Package\Orm\Collection\PersistentCollection;
use NeoPHP\Package\Orm\Contract\CollectionInterface;
use NeoPHP\Package\Orm\Contract\OrmInterface;
use NeoPHP\Package\Orm\Contract\ProxyInterface;
use NeoPHP\Package\Orm\Event\LifecycleEvent;
use NeoPHP\Package\Orm\Event\PostFlushEvent;
use NeoPHP\Package\Orm\Event\PostPersistEvent;
use NeoPHP\Package\Orm\Event\PostRemoveEvent;
use NeoPHP\Package\Orm\Event\PostUpdateEvent;
use NeoPHP\Package\Orm\Event\PreFlushEvent;
use NeoPHP\Package\Orm\Event\PrePersistEvent;
use NeoPHP\Package\Orm\Event\PreRemoveEvent;
use NeoPHP\Package\Orm\Event\PreUpdateEvent;
use NeoPHP\Package\Orm\Exception\EntityNotFoundException;
use NeoPHP\Package\Orm\Exception\OrmException;
use NeoPHP\Package\Orm\Mapping\GeneratedValue;
use NeoPHP\Package\Orm\Metadata\ClassMetadata;
use NeoPHP\Package\Orm\Type\Type;
use ReflectionMethod;
use Throwable;

class UnitOfWork
{
    public const STATE_MANAGED = 1;
    public const STATE_NEW = 2;
    public const STATE_DETACHED = 3;
    public const STATE_REMOVED = 4;

    public const MAX_FLUSH_STATISTICS = 100;

    protected array $identityMap = [];

    protected array $states = [];

    protected array $entities = [];

    protected array $originalData = [];

    protected array $scheduledInserts = [];

    protected array $scheduledRemovals = [];

    protected array $changeSets = [];

    protected array $collectionUpdates = [];

    protected Hydrator $hydrator;

    protected array $flushes = [];

    protected int $flushCount = 0;

    protected int $initializedProxies = 0;

    public function __construct(protected OrmInterface $orm)
    {
        $this->hydrator = new Hydrator($orm, $this);
    }

    public function getHydrator(): Hydrator
    {
        return $this->hydrator;
    }

    public function getState(object $entity): int
    {
        return $this->states[spl_object_id($entity)] ?? self::STATE_NEW;
    }

    public function isManaged(object $entity): bool
    {
        return ($this->states[spl_object_id($entity)] ?? null) === self::STATE_MANAGED;
    }

    public function isScheduledForInsert(object $entity): bool
    {
        return isset($this->scheduledInserts[spl_object_id($entity)]);
    }

    public function isScheduledForRemoval(object $entity): bool
    {
        return isset($this->scheduledRemovals[spl_object_id($entity)]);
    }

    public function contains(object $entity): bool
    {
        $state = $this->states[spl_object_id($entity)] ?? null;

        return $state === self::STATE_MANAGED || ($state === self::STATE_NEW && $this->isScheduledForInsert($entity));
    }

    public function getStatistics(): array
    {
        $managed = [];

        foreach ($this->identityMap as $class => $entities) {
            $managed[$class] = count($entities);
        }

        return [
            'managed' => $managed,
            'scheduled_inserts' => count($this->scheduledInserts),
            'scheduled_removals' => count($this->scheduledRemovals),
            'flush_count' => $this->flushCount,
            'flushes' => $this->flushes,
            'initialized_proxies' => $this->initializedProxies,
        ];
    }

    public function getIdentityMap(): array
    {
        return $this->identityMap;
    }

    public function tryGetById(string $class, mixed $id): ?object
    {
        return $this->identityMap[$class][(string) $id] ?? null;
    }

    public function registerManaged(object $entity, ClassMetadata $metadata, mixed $id, ?array $data = null): void
    {
        $oid = spl_object_id($entity);
        $this->identityMap[$metadata->name][(string) $id] = $entity;
        $this->states[$oid] = self::STATE_MANAGED;
        $this->entities[$oid] = $entity;

        if ($data !== null) {
            $this->originalData[$oid] = $data;
        }
    }

    public function setOriginalData(object $entity, array $data): void
    {
        $this->originalData[spl_object_id($entity)] = $data;
    }

    public function getOriginalData(object $entity): array
    {
        return $this->originalData[spl_object_id($entity)] ?? [];
    }

    public function getEntityData(ClassMetadata $metadata, object $entity): array
    {
        $data = [];

        foreach ($metadata->fields as $field => $mapping) {
            $data[$field] = Type::toDatabase($metadata->getValue($entity, $field), $mapping['type'], $mapping['enumType']);
        }

        foreach ($metadata->getOwningToOneAssociations() as $field => $association) {
            $related = $metadata->getValue($entity, $field);
            $data[$field] = $related === null ? null : $this->getRelatedId($association, $related);
        }

        return $data;
    }

    public function persist(object $entity, array &$visited = []): void
    {
        $oid = spl_object_id($entity);

        if (isset($visited[$oid])) {
            return;
        }

        $visited[$oid] = true;
        $metadata = $this->orm->getMetadata($entity);
        $state = $this->states[$oid] ?? null;

        if ($state === self::STATE_REMOVED) {
            unset($this->scheduledRemovals[$oid]);
            $this->states[$oid] = self::STATE_MANAGED;
        } elseif ($state === null || ($state === self::STATE_NEW && !isset($this->scheduledInserts[$oid])) || $state === self::STATE_DETACHED) {
            if ($state === self::STATE_DETACHED) {
                throw new OrmException('A detached entity of class "{class}" cannot be persisted again.', 0, null, ['class' => $metadata->name]);
            }

            $this->invokeLifecycle($metadata, $entity, 'prePersist', new PrePersistEvent($entity, $this->orm));

            if ($metadata->generator === GeneratedValue::UUID && $metadata->getIdentifierValue($entity) === null) {
                $metadata->setValue($entity, $metadata->identifier, self::uuid());
            }

            $this->states[$oid] = self::STATE_NEW;
            $this->entities[$oid] = $entity;
            $this->scheduledInserts[$oid] = $entity;
        }

        foreach ($metadata->associations as $field => $association) {
            if (!$association['cascade']['persist']) {
                continue;
            }

            foreach ($this->getRelatedEntities($metadata, $entity, $field, false) as $related) {
                $this->persist($related, $visited);
            }
        }
    }

    public function remove(object $entity, array &$visited = []): void
    {
        $oid = spl_object_id($entity);

        if (isset($visited[$oid])) {
            return;
        }

        $visited[$oid] = true;
        $metadata = $this->orm->getMetadata($entity);
        $state = $this->states[$oid] ?? null;

        if ($state === null || $state === self::STATE_DETACHED) {
            throw new OrmException('Unable to remove an entity of class "{class}" that is not managed.', 0, null, ['class' => $metadata->name]);
        }

        foreach ($metadata->associations as $field => $association) {
            if ($association['cascade']['remove']) {
                foreach ($this->getRelatedEntities($metadata, $entity, $field, true) as $related) {
                    if ($this->contains($related)) {
                        $this->remove($related, $visited);
                    }
                }
            }
        }

        if ($state === self::STATE_NEW) {
            unset($this->scheduledInserts[$oid], $this->states[$oid], $this->entities[$oid]);

            return;
        }

        if ($state === self::STATE_REMOVED) {
            return;
        }

        $this->invokeLifecycle($metadata, $entity, 'preRemove', new PreRemoveEvent($entity, $this->orm));
        $this->states[$oid] = self::STATE_REMOVED;
        $this->scheduledRemovals[$oid] = $entity;
    }

    public function detach(object $entity): void
    {
        $oid = spl_object_id($entity);

        if (!isset($this->states[$oid])) {
            return;
        }

        $metadata = $this->orm->getMetadata($entity);
        $id = $metadata->getIdentifierValue($entity);

        if ($id !== null && ($this->identityMap[$metadata->name][(string) $id] ?? null) === $entity) {
            unset($this->identityMap[$metadata->name][(string) $id]);
        }

        unset($this->scheduledInserts[$oid], $this->scheduledRemovals[$oid], $this->entities[$oid], $this->originalData[$oid]);
        $this->states[$oid] = self::STATE_DETACHED;
    }

    public function clear(): void
    {
        $this->identityMap = [];
        $this->states = [];
        $this->entities = [];
        $this->originalData = [];
        $this->scheduledInserts = [];
        $this->scheduledRemovals = [];
        $this->changeSets = [];
        $this->collectionUpdates = [];
    }

    public function find(ClassMetadata $metadata, mixed $id): ?object
    {
        $existing = $this->tryGetById($metadata->name, $id);

        if ($existing !== null) {
            if ($existing instanceof ProxyInterface && !$existing->__neoIsInitialized()) {
                try {
                    $existing->__neoLoad();
                } catch (EntityNotFoundException) {
                    return null;
                }
            }

            return $this->getState($existing) === self::STATE_REMOVED ? null : $existing;
        }

        $row = $this->fetchRow($metadata, $id);

        return $row === null ? null : $this->hydrator->hydrate($metadata, $row);
    }

    public function getReference(ClassMetadata $metadata, mixed $id): object
    {
        $existing = $this->tryGetById($metadata->name, $id);

        if ($existing !== null) {
            return $existing;
        }

        $factory = $this->orm->getProxyFactory();

        if (!$factory->canProxy($metadata->name)) {
            return $this->find($metadata, $id) ?? throw new EntityNotFoundException('The entity "{class}" with the identifier "{id}" was not found.', 0, null, ['class' => $metadata->name, 'id' => $id]);
        }

        $proxy = $factory->create($metadata, $id, function (ProxyInterface $proxy) use ($metadata, $id): void {
            $row = $this->fetchRow($metadata, $id);

            if ($row === null) {
                throw new EntityNotFoundException('The entity "{class}" with the identifier "{id}" was not found.', 0, null, ['class' => $metadata->name, 'id' => $id]);
            }

            $this->hydrator->hydrate($metadata, $row, $proxy);
            $this->initializedProxies++;
        });

        $this->registerManaged($proxy, $metadata, $id);

        return $proxy;
    }

    public function refresh(object $entity): void
    {
        $metadata = $this->orm->getMetadata($entity);
        $id = $metadata->getIdentifierValue($entity);
        $row = $id === null ? null : $this->fetchRow($metadata, $id);

        if ($row === null) {
            throw new EntityNotFoundException('The entity "{class}" with the identifier "{id}" was not found.', 0, null, ['class' => $metadata->name, 'id' => $id]);
        }

        $this->hydrator->hydrate($metadata, $row, $entity, true);
    }

    public function fetchRow(ClassMetadata $metadata, mixed $id): ?array
    {
        $connection = $this->orm->getConnection();
        $sql = sprintf('SELECT * FROM %s WHERE %s = ?', $connection->quoteIdentifier($metadata->table), $connection->quoteIdentifier($metadata->getIdentifierColumn()));

        return $connection->fetchAssociative($sql, [Type::toDatabase($id, $metadata->getIdentifierType())]);
    }

    public function loadCollection(PersistentCollection $collection): array
    {
        $owner = $collection->getOwner();
        $association = $collection->getAssociation();
        $ownerMetadata = $this->orm->getMetadata($owner);
        $target = $this->orm->getMetadata($association['target']);
        $connection = $this->orm->getConnection();
        $id = Type::toDatabase($ownerMetadata->getIdentifierValue($owner), $ownerMetadata->getIdentifierType());
        $table = $connection->quoteIdentifier($target->table);

        if ($association['type'] === ClassMetadata::MANY_TO_MANY) {
            $sql = sprintf(
                'SELECT t.* FROM %s t INNER JOIN %s j ON j.%s = t.%s WHERE j.%s = ?',
                $table,
                $connection->quoteIdentifier($association['joinTable']),
                $connection->quoteIdentifier($association['inverseJoinColumn']),
                $connection->quoteIdentifier($target->getIdentifierColumn()),
                $connection->quoteIdentifier($association['joinColumn']),
            );
        } else {
            $sql = sprintf('SELECT t.* FROM %s t WHERE t.%s = ?', $table, $connection->quoteIdentifier($association['joinColumn']));
        }

        $order = [];

        foreach ((array) $association['orderBy'] as $field => $direction) {
            $order[] = 't.' . $connection->quoteIdentifier($target->getColumnName((string) $field)) . (strtoupper((string) $direction) === 'DESC' ? ' DESC' : ' ASC');
        }

        if ($order !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $order);
        }

        $elements = [];

        foreach ($connection->fetchAllAssociative($sql, [$id]) as $row) {
            $element = $this->hydrator->hydrate($target, $row);

            if ($this->getState($element) !== self::STATE_REMOVED) {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    public function flush(): void
    {
        $start = microtime(true);

        $this->dispatch(new PreFlushEvent($this->orm));
        $this->computeChangeSets();

        if ($this->scheduledInserts === [] && $this->scheduledRemovals === [] && $this->changeSets === [] && $this->collectionUpdates === []) {
            $this->recordFlush($start, 0, 0, 0, 0);

            $this->dispatch(new PostFlushEvent($this->orm));

            return;
        }

        $connection = $this->orm->getConnection();
        $inserts = $this->sortByDependencies($this->scheduledInserts);
        $removals = array_reverse($this->sortByDependencies($this->scheduledRemovals)[0]);
        $inserted = [];
        $updated = [];
        $removed = [];

        $connection->beginTransaction();

        try {
            foreach ($inserts[0] as $entity) {
                $this->executeInsert($entity, $inserts[1][spl_object_id($entity)] ?? []);
                $inserted[] = $entity;
            }

            foreach ($inserts[1] as $oid => $fields) {
                $entity = $this->entities[$oid];
                $metadata = $this->orm->getMetadata($entity);
                $data = [];

                foreach ($fields as $field) {
                    $data[$metadata->getColumnName($field)] = $this->getRelatedId($metadata->associations[$field], $metadata->getValue($entity, $field));
                }

                $this->executeUpdate($metadata, $entity, $data);
            }

            foreach ($this->changeSets as $oid => $changeSet) {
                $entity = $this->entities[$oid];
                $metadata = $this->orm->getMetadata($entity);

                if ($metadata->getCallbacks('preUpdate') !== [] || $this->orm->getEventDispatcher()?->hasListeners(PreUpdateEvent::class)) {
                    $this->invokeLifecycle($metadata, $entity, 'preUpdate', new PreUpdateEvent($entity, $this->orm, $this->toPhpChangeSet($metadata, $entity, $changeSet)));
                    $changeSet = $this->computeChangeSet($metadata, $entity) ?? [];
                }

                if ($changeSet === []) {
                    continue;
                }

                $data = [];

                foreach ($changeSet as $field => $values) {
                    $data[$metadata->getColumnName($field)] = $values[1];
                }

                $this->executeUpdate($metadata, $entity, $data);
                $updated[] = $entity;
            }

            foreach ($this->collectionUpdates as $update) {
                $this->executeCollectionUpdate(...$update);
            }

            foreach ($removals as $entity) {
                $this->executeDelete($entity);
                $removed[] = $entity;
            }

            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            foreach ($inserted as $entity) {
                $metadata = $this->orm->getMetadata($entity);

                if ($metadata->isIdGenerated()) {
                    $metadata->setValue($entity, $metadata->identifier, null);
                }
            }

            throw $exception;
        }

        foreach ($inserted as $entity) {
            $metadata = $this->orm->getMetadata($entity);
            $oid = spl_object_id($entity);
            unset($this->scheduledInserts[$oid]);
            $this->registerManaged($entity, $metadata, $metadata->getIdentifierValue($entity), $this->getEntityData($metadata, $entity));
            $this->wrapCollections($metadata, $entity);
            $this->invokeLifecycle($metadata, $entity, 'postPersist', new PostPersistEvent($entity, $this->orm));
        }

        foreach ($updated as $entity) {
            $metadata = $this->orm->getMetadata($entity);
            $this->setOriginalData($entity, $this->getEntityData($metadata, $entity));
            $this->invokeLifecycle($metadata, $entity, 'postUpdate', new PostUpdateEvent($entity, $this->orm));
        }

        foreach ($this->collectionUpdates as $update) {
            $collection = $update[1];

            if ($collection instanceof PersistentCollection) {
                $collection->takeSnapshot();
            }
        }

        foreach ($this->entities as $entity) {
            if ($this->getState($entity) === self::STATE_MANAGED && !($entity instanceof ProxyInterface && !$entity->__neoIsInitialized())) {
                $metadata = $this->orm->getMetadata($entity);

                foreach ($metadata->associations as $field => $association) {
                    $value = $metadata->getValue($entity, $field);

                    if ($value instanceof PersistentCollection && $value->isInitialized()) {
                        $value->takeSnapshot();
                    }
                }
            }
        }

        foreach ($removed as $entity) {
            $metadata = $this->orm->getMetadata($entity);
            $oid = spl_object_id($entity);
            $id = $metadata->getIdentifierValue($entity);
            unset($this->identityMap[$metadata->name][(string) $id], $this->scheduledRemovals[$oid], $this->entities[$oid], $this->originalData[$oid], $this->states[$oid]);

            if ($metadata->isIdGenerated()) {
                $metadata->setValue($entity, $metadata->identifier, null);
            }

            $this->invokeLifecycle($metadata, $entity, 'postRemove', new PostRemoveEvent($entity, $this->orm));
        }

        $this->recordFlush($start, count($inserted), count($updated), count($removed), count($this->collectionUpdates));
        $this->changeSets = [];
        $this->collectionUpdates = [];

        $this->dispatch(new PostFlushEvent($this->orm));
    }

    protected function recordFlush(float $start, int $inserts, int $updates, int $deletes, int $collections): void
    {
        $this->flushCount++;

        if (count($this->flushes) < self::MAX_FLUSH_STATISTICS) {
            $this->flushes[] = [
                'inserts' => $inserts,
                'updates' => $updates,
                'deletes' => $deletes,
                'collections' => $collections,
                'duration' => round((microtime(true) - $start) * 1000, 3),
            ];
        }
    }

    public function invokeLifecycle(ClassMetadata $metadata, object $entity, string $event, LifecycleEvent $object): void
    {
        foreach ($metadata->getCallbacks($event) as $method) {
            $reflection = new ReflectionMethod($entity, $method);
            $reflection->getNumberOfParameters() > 0 ? $reflection->invoke($entity, $object) : $reflection->invoke($entity);
        }

        $this->dispatch($object);
    }

    public static function uuid(): string
    {
        $time = str_pad(dechex((int) floor(microtime(true) * 1000)), 12, '0', STR_PAD_LEFT);
        $random = random_bytes(10);
        $random[0] = chr((ord($random[0]) & 0x0F) | 0x70);
        $random[2] = chr((ord($random[2]) & 0x3F) | 0x80);
        $hex = $time . bin2hex($random);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    protected function computeChangeSets(): void
    {
        $this->changeSets = [];
        $this->collectionUpdates = [];

        do {
            $count = count($this->entities);

            foreach ($this->entities as $entity) {
                $state = $this->getState($entity);

                if ($state === self::STATE_REMOVED || ($entity instanceof ProxyInterface && !$entity->__neoIsInitialized())) {
                    continue;
                }

                $this->discoverRelated($entity);
            }
        } while (count($this->entities) !== $count);

        foreach ($this->entities as $oid => $entity) {
            $state = $this->getState($entity);

            if ($state === self::STATE_REMOVED || ($entity instanceof ProxyInterface && !$entity->__neoIsInitialized())) {
                continue;
            }

            $metadata = $this->orm->getMetadata($entity);

            if ($state === self::STATE_MANAGED) {
                $changeSet = $this->computeChangeSet($metadata, $entity);

                if ($changeSet !== null && $changeSet !== []) {
                    $this->changeSets[$oid] = $changeSet;
                }
            }

            foreach ($metadata->associations as $field => $association) {
                if ($association['type'] !== ClassMetadata::MANY_TO_MANY || !$association['owning']) {
                    continue;
                }

                $value = $metadata->getValue($entity, $field);

                if ($value instanceof PersistentCollection) {
                    if ($value->isInitialized() && $value->isDirty()) {
                        $this->collectionUpdates[] = [$entity, $value, $association, $value->getInsertDiff(), $value->getDeleteDiff()];
                    }
                } elseif ($value instanceof CollectionInterface && $value->count() > 0) {
                    $this->collectionUpdates[] = [$entity, $value, $association, $value->getValues(), []];
                }
            }
        }
    }

    protected function discoverRelated(object $entity): void
    {
        $metadata = $this->orm->getMetadata($entity);
        $state = $this->getState($entity);

        foreach ($metadata->associations as $field => $association) {
            $value = $metadata->getValue($entity, $field);

            if ($association['orphanRemoval'] && $state === self::STATE_MANAGED && $value instanceof PersistentCollection) {
                foreach ($value->getDeleteDiff() as $orphan) {
                    if ($this->isManaged($orphan)) {
                        $this->remove($orphan);
                    }
                }
            }

            if ($association['orphanRemoval'] && $state === self::STATE_MANAGED && $association['type'] === ClassMetadata::ONE_TO_ONE && $association['owning']) {
                $original = $this->originalData[spl_object_id($entity)][$field] ?? null;
                $current = $value === null ? null : $this->getRelatedId($association, $value);

                if ($original !== null && $original !== $current) {
                    $orphan = $this->tryGetById($association['target'], $original);

                    if ($orphan !== null && $this->isManaged($orphan)) {
                        $this->remove($orphan);
                    }
                }
            }

            foreach ($this->getRelatedEntities($metadata, $entity, $field, false) as $related) {
                $relatedState = $this->states[spl_object_id($related)] ?? null;

                if ($relatedState === self::STATE_MANAGED || $relatedState === self::STATE_REMOVED || ($relatedState === self::STATE_NEW && $this->isScheduledForInsert($related))) {
                    continue;
                }

                if (!$association['cascade']['persist']) {
                    throw new OrmException('A new entity of class "{target}" was found through "{class}::${field}", which is not configured to cascade persist: persist it explicitly or add cascade: [\'persist\'].', 0, null, [
                        'target' => $related::class,
                        'class' => $metadata->name,
                        'field' => $field,
                    ]);
                }

                $this->persist($related);
            }
        }
    }

    protected function computeChangeSet(ClassMetadata $metadata, object $entity): ?array
    {
        $oid = spl_object_id($entity);

        if (!isset($this->originalData[$oid])) {
            return null;
        }

        $original = $this->originalData[$oid];
        $changeSet = [];

        foreach ($this->getEntityData($metadata, $entity) as $field => $value) {
            if ($field === $metadata->identifier) {
                continue;
            }

            $old = $original[$field] ?? null;

            if ($old !== $value && !(is_scalar($old) && is_scalar($value) && (string) $old === (string) $value && gettype($old) === gettype($value))) {
                $changeSet[$field] = [$old, $value];
            }
        }

        return $changeSet;
    }

    protected function toPhpChangeSet(ClassMetadata $metadata, object $entity, array $changeSet): array
    {
        $result = [];

        foreach ($changeSet as $field => $values) {
            $old = $values[0];

            if ($metadata->hasField($field)) {
                $old = Type::toPhp($old, $metadata->fields[$field]['type'], $metadata->fields[$field]['enumType']);
            } elseif ($old !== null) {
                $old = $this->tryGetById($metadata->associations[$field]['target'], $old) ?? $old;
            }

            $result[$field] = [$old, $metadata->getValue($entity, $field)];
        }

        return $result;
    }

    protected function executeInsert(object $entity, array $deferred): void
    {
        $metadata = $this->orm->getMetadata($entity);
        $connection = $this->orm->getConnection();
        $data = [];

        foreach ($metadata->fields as $field => $mapping) {
            if ($field === $metadata->identifier && $metadata->isIdGenerated() && $metadata->getValue($entity, $field) === null) {
                continue;
            }

            $data[$mapping['column']] = Type::toDatabase($metadata->getValue($entity, $field), $mapping['type'], $mapping['enumType']);
        }

        foreach ($metadata->getOwningToOneAssociations() as $field => $association) {
            $related = in_array($field, $deferred, true) ? null : $metadata->getValue($entity, $field);
            $data[$association['joinColumn']] = $related === null ? null : $this->getRelatedId($association, $related);
        }

        if ($metadata->identifier !== '' && !$metadata->isIdGenerated() && $metadata->getIdentifierValue($entity) === null) {
            throw new OrmException('The entity "{class}" has no identifier: set it before flush or use #[ORM\GeneratedValue].', 0, null, ['class' => $metadata->name]);
        }

        $table = $connection->quoteIdentifier($metadata->table);

        if ($data === []) {
            $sql = $connection->getDriver()->getName() === 'mysql' ? sprintf('INSERT INTO %s () VALUES ()', $table) : sprintf('INSERT INTO %s DEFAULT VALUES', $table);
            $params = [];
        } else {
            $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', array_map(fn (string $column): string => $connection->quoteIdentifier($column), array_keys($data))), implode(', ', array_fill(0, count($data), '?')));
            $params = array_values($data);
        }

        if ($metadata->isIdGenerated() && $metadata->getIdentifierValue($entity) === null) {
            if ($connection->getDriver()->getName() === 'pgsql') {
                $id = $connection->fetchOne($sql . ' RETURNING ' . $connection->quoteIdentifier($metadata->getIdentifierColumn()), $params);
            } else {
                $connection->executeStatement($sql, $params);
                $id = $connection->lastInsertId();
            }

            $metadata->setValue($entity, $metadata->identifier, Type::toPhp($id, $metadata->getIdentifierType()));

            return;
        }

        $connection->executeStatement($sql, $params);
    }

    protected function executeUpdate(ClassMetadata $metadata, object $entity, array $data): void
    {
        if ($data === []) {
            return;
        }

        $this->orm->getConnection()->update($metadata->table, $data, [$metadata->getIdentifierColumn() => Type::toDatabase($metadata->getIdentifierValue($entity), $metadata->getIdentifierType())]);
    }

    protected function executeDelete(object $entity): void
    {
        $metadata = $this->orm->getMetadata($entity);
        $connection = $this->orm->getConnection();
        $id = Type::toDatabase($metadata->getIdentifierValue($entity), $metadata->getIdentifierType());

        foreach ($metadata->associations as $association) {
            if ($association['type'] === ClassMetadata::MANY_TO_MANY) {
                $connection->delete($association['joinTable'], [$association['joinColumn'] => $id]);
            }
        }

        $connection->delete($metadata->table, [$metadata->getIdentifierColumn() => $id]);
    }

    protected function executeCollectionUpdate(object $owner, CollectionInterface $collection, array $association, array $inserts, array $deletes): void
    {
        $connection = $this->orm->getConnection();
        $ownerMetadata = $this->orm->getMetadata($owner);
        $ownerId = Type::toDatabase($ownerMetadata->getIdentifierValue($owner), $ownerMetadata->getIdentifierType());

        foreach ($deletes as $element) {
            if ($this->getState($element) === self::STATE_REMOVED) {
                continue;
            }

            $connection->delete($association['joinTable'], [
                $association['joinColumn'] => $ownerId,
                $association['inverseJoinColumn'] => $this->getRelatedId($association, $element),
            ]);
        }

        foreach ($inserts as $element) {
            $connection->insert($association['joinTable'], [
                $association['joinColumn'] => $ownerId,
                $association['inverseJoinColumn'] => $this->getRelatedId($association, $element),
            ]);
        }
    }

    protected function wrapCollections(ClassMetadata $metadata, object $entity): void
    {
        foreach ($metadata->associations as $field => $association) {
            if (!in_array($association['type'], ClassMetadata::TO_MANY, true)) {
                continue;
            }

            $value = $metadata->getValue($entity, $field);

            if ($value instanceof PersistentCollection) {
                continue;
            }

            $collection = new PersistentCollection($entity, $association, null, $value instanceof CollectionInterface ? $value->toArray() : []);
            $collection->takeSnapshot();
            $metadata->setValue($entity, $field, $collection);
        }
    }

    protected function getRelatedEntities(ClassMetadata $metadata, object $entity, string $field, bool $initialize): array
    {
        $value = $metadata->getValue($entity, $field);

        if ($value === null) {
            return [];
        }

        if ($value instanceof PersistentCollection) {
            if (!$value->isInitialized() && !$initialize) {
                return [];
            }

            return $value->getValues();
        }

        if ($value instanceof CollectionInterface) {
            return $value->getValues();
        }

        if (is_iterable($value)) {
            return is_array($value) ? array_values($value) : iterator_to_array($value, false);
        }

        return is_object($value) ? [$value] : [];
    }

    protected function getRelatedId(array $association, object $related): mixed
    {
        $metadata = $this->orm->getMetadata($related);

        return Type::toDatabase($metadata->getIdentifierValue($related), $metadata->getIdentifierType());
    }

    protected function sortByDependencies(array $entities): array
    {
        $sorted = [];
        $marks = [];
        $deferred = [];

        $visit = function (object $entity) use (&$visit, &$sorted, &$marks, &$deferred, $entities): void {
            $oid = spl_object_id($entity);

            if (($marks[$oid] ?? null) === 2) {
                return;
            }

            $marks[$oid] = 1;
            $metadata = $this->orm->getMetadata($entity);

            foreach ($metadata->getOwningToOneAssociations() as $field => $association) {
                $related = $metadata->getValue($entity, $field);

                if ($related === null || !isset($entities[spl_object_id($related)]) || $related === $entity) {
                    if ($related === $entity && isset($entities[$oid])) {
                        $deferred[$oid][] = $field;
                    }

                    continue;
                }

                if (($marks[spl_object_id($related)] ?? null) === 1) {
                    if (!$association['nullable']) {
                        throw new OrmException('Unable to order the inserts: circular non-nullable relation on "{class}::${field}".', 0, null, ['class' => $metadata->name, 'field' => $field]);
                    }

                    $deferred[$oid][] = $field;
                    continue;
                }

                $visit($related);
            }

            $marks[$oid] = 2;
            $sorted[] = $entity;
        };

        foreach ($entities as $entity) {
            $visit($entity);
        }

        return [$sorted, $deferred];
    }

    protected function dispatch(object $event): void
    {
        $this->orm->getEventDispatcher()?->dispatch($event);
    }
}