<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Event;

use NeoPHP\Package\Orm\OrmManagerInterface;

class PreUpdateEvent extends LifecycleEvent
{
    public function __construct(object $entity, OrmManagerInterface $orm, protected array $changeSet = [])
    {
        parent::__construct($entity, $orm);
    }

    public function getChangeSet(): array
    {
        return $this->changeSet;
    }

    public function hasChangedField(string $field): bool
    {
        return array_key_exists($field, $this->changeSet);
    }

    public function getOldValue(string $field): mixed
    {
        return $this->changeSet[$field][0] ?? null;
    }

    public function getNewValue(string $field): mixed
    {
        return $this->changeSet[$field][1] ?? null;
    }
}