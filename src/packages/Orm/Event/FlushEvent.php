<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Event;

use NeoPHP\Component\Event\Contract\AbstractEvent;
use NeoPHP\Package\Orm\OrmManagerInterface;

abstract class FlushEvent extends AbstractEvent
{
    public function __construct(protected OrmManagerInterface $orm)
    {
    }

    public function getOrm(): OrmManagerInterface
    {
        return $this->orm;
    }
}