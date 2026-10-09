<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Helper\Controller;

use NeoPHP\Package\Orm\Contract\RepositoryInterface;
use NeoPHP\Package\Orm\OrmManagerInterface;

trait OrmController
{
    abstract protected function get(string $id): mixed;

    protected function getOrm(): OrmManagerInterface
    {
        return $this->get(OrmManagerInterface::class);
    }

    protected function getRepository(string $entityClass): RepositoryInterface
    {
        return $this->getOrm()->getRepository($entityClass);
    }
}