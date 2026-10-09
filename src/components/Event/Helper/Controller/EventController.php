<?php

declare(strict_types=1);

namespace NeoPHP\Component\Event\Helper\Controller;

use NeoPHP\Component\Event\EventManagerInterface;

trait EventController
{
    abstract protected function get(string $id): mixed;

    protected function dispatch(object $event): object
    {
        return $this->get(EventManagerInterface::class)->dispatch($event);
    }
}