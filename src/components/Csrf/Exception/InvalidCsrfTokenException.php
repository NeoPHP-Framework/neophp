<?php

declare(strict_types=1);

namespace NeoPHP\Component\Csrf\Exception;

class InvalidCsrfTokenException extends CsrfException
{
    protected int $statusCode = 403;
}