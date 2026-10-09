<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Exception;

class NotNormalizableValueException extends SerializerException
{
    protected int $statusCode = 422;

    public function getPath(): ?string
    {
        $path = $this->context['path'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }
}