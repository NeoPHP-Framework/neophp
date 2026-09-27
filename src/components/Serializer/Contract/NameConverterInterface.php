<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Contract;

interface NameConverterInterface
{
    public function normalize(string $propertyName): string;

    public function denormalize(string $propertyName): string;
}