<?php

declare(strict_types=1);

namespace NeoPHP\Component\Exception\Contract;

use Exception;
use Stringable;
use Throwable;

abstract class AbstractException extends Exception implements ExceptionInterface
{
    protected array $context = [];

    protected int $statusCode = 500;

    protected array $headers = [];

    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, array $context = [])
    {
        $this->context = $context;

        parent::__construct(static::interpolate($message, $context), $code, $previous);
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function setContext(array $context): static
    {
        $this->context = $context;

        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function setStatusCode(int $statusCode): static
    {
        $this->statusCode = $statusCode;

        return $this;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function setHeaders(array $headers): static
    {
        $this->headers = $headers;

        return $this;
    }

    public function getStackTrace(): array
    {
        $frames = [];

        foreach ($this->getTrace() as $index => $frame) {
            $frames[] = [
                'index' => $index,
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'class' => $frame['class'] ?? null,
                'type' => $frame['type'] ?? null,
                'function' => $frame['function'],
                'call' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'] . '()',
            ];
        }

        return $frames;
    }

    public function getPreviousExceptions(): array
    {
        $previous = [];
        $current = $this->getPrevious();

        while ($current !== null) {
            $previous[] = $current;
            $current = $current->getPrevious();
        }

        return $previous;
    }

    public function getShortName(): string
    {
        $position = strrpos(static::class, '\\');

        return $position === false ? static::class : substr(static::class, $position + 1);
    }

    public function toArray(bool $withTrace = true): array
    {
        $data = [
            'class' => static::class,
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'status' => $this->statusCode,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'context' => $this->context,
        ];

        if ($withTrace) {
            $data['trace'] = $this->getStackTrace();
        }

        $data['previous'] = array_map(
            static fn (Throwable $exception): array => [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ],
            $this->getPreviousExceptions(),
        );

        return $data;
    }

    protected static function interpolate(string $message, array $context): string
    {
        $replacements = [];

        foreach ($context as $key => $value) {
            $replacements['{' . $key . '}'] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value), $value instanceof Stringable => (string) $value,
                $value === null => 'null',
                default => get_debug_type($value),
            };
        }

        return strtr($message, $replacements);
    }
}