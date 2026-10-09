<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Exception;

class ExtraAttributesException extends NotNormalizableValueException
{
    public function getExtraAttributes(): array
    {
        return (array) ($this->context['extra'] ?? []);
    }
}