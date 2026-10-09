<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Event;

use NeoPHP\Component\Event\Contract\AbstractEvent;
use NeoPHP\Package\Orm\OrmManagerInterface;

abstract class LifecycleEvent extends AbstractEvent
{
    public function __construct(protected object $entity, protected OrmManagerInterface $orm)
    {
    }

    public function getEntity(): object
    {
        return $this->entity;
    }

    public function getOrm(): OrmManagerInterface
    {
        return $this->orm;
    }
}