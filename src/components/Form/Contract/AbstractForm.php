<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Contract;

abstract class AbstractForm extends AbstractType
{
    protected ?string $entityClass = null;

    public function getEntityClass(): ?string
    {
        return $this->entityClass;
    }
}