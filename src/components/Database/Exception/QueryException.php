<?php

declare(strict_types=1);

namespace NeoPHP\Component\Database\Exception;

use Throwable;

class QueryException extends DatabaseException
{
    public static function fromThrowable(Throwable $exception, string $sql, array $params = []): static
    {
        return new static('An error occurred while executing the query "{sql}": {error}', 0, $exception, [
            'sql' => $sql,
            'params' => $params,
            'error' => $exception->getMessage(),
        ]);
    }

    public function getSql(): string
    {
        return (string) ($this->context['sql'] ?? '');
    }

    public function getParams(): array
    {
        return (array) ($this->context['params'] ?? []);
    }
}