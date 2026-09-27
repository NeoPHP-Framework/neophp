<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\NameConverter;

use NeoPHP\Component\Serializer\Contract\NameConverterInterface;

class SnakeCaseToCamelCaseNameConverter implements NameConverterInterface
{
    public function __construct(protected CamelCaseToSnakeCaseNameConverter $inner = new CamelCaseToSnakeCaseNameConverter())
    {
    }

    public function normalize(string $propertyName): string
    {
        return $this->inner->denormalize($propertyName);
    }

    public function denormalize(string $propertyName): string
    {
        return $this->inner->normalize($propertyName);
    }
}