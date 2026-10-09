<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\Exception;

use Throwable;

class CommandNotFoundException extends ConsoleException
{
    protected array $alternatives = [];

    public function __construct(string $message = '', array $alternatives = [], array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous, $context);

        $this->alternatives = $alternatives;
    }

    public function getAlternatives(): array
    {
        return $this->alternatives;
    }
}