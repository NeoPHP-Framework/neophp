<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\NameConverter;

use NeoPHP\Component\Serializer\Contract\NameConverterInterface;

class CamelCaseToSnakeCaseNameConverter implements NameConverterInterface
{
    public function normalize(string $propertyName): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])|(?<=[A-Z])([A-Z][a-z])/', '_$1$2', $propertyName));
    }

    public function denormalize(string $propertyName): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $propertyName))));
    }
}