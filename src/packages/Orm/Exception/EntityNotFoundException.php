<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Exception;

class EntityNotFoundException extends OrmException
{
    protected int $statusCode = 404;
}