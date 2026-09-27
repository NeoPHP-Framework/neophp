<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Transport;

use JsonException;
use NeoPHP\Component\HttpClient\Exception\InvalidOptionException;

class MockResponse
{
    public function __construct(protected string $body = '', protected int $status = 200, protected array $headers = [], protected array $info = [])
    {
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): static
    {
        try {
            $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new InvalidOptionException('The mock response data cannot be encoded as JSON: {error}.', 0, $exception, ['error' => $exception->getMessage()]);
        }

        return new static($body, $status, array_replace(['Content-Type' => 'application/json'], $headers));
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getInfo(): array
    {
        return $this->info;
    }
}