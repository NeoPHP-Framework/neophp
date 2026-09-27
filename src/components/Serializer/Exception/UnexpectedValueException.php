<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Exception;

class UnexpectedValueException extends SerializerException
{
    protected int $statusCode = 400;
}