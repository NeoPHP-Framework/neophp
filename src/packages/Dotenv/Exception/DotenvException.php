<?php

declare(strict_types=1);

namespace NeoPHP\Package\Dotenv\Exception;

use NeoPHP\Component\Exception\Exception\FrameworkException;

class DotenvException extends FrameworkException
{
    public static function syntax(string $message, int $line, ?string $path): static
    {
        return new static('{message} at line {line}{path}.', 0, null, [
            'message' => $message,
            'line' => $line,
            'path' => $path !== null ? sprintf(' in "%s"', $path) : '',
        ]);
    }
}