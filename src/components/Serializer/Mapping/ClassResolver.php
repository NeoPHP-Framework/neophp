<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Mapping;

use NeoPHP\Package\Orm\Contract\ProxyInterface;
use NeoPHP\Package\Orm\Metadata\MetadataFactory as OrmMetadataFactory;

class ClassResolver
{
    public static function getRealClass(string|object $class): string
    {
        $class = is_object($class) ? $class::class : ltrim($class, '\\');

        if (interface_exists(ProxyInterface::class) && is_subclass_of($class, ProxyInterface::class)) {
            return OrmMetadataFactory::getRealClass($class);
        }

        return $class;
    }
}