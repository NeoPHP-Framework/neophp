<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Exception;

class MissingConstructorArgumentsException extends NotNormalizableValueException
{
    public function getMissingArguments(): array
    {
        return (array) ($this->context['missing'] ?? []);
    }
}