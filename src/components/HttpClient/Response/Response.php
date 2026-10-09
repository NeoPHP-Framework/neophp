<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Response;

use JsonException;
use NeoPHP\Component\HttpClient\Contract\ResponseInterface;
use NeoPHP\Component\HttpClient\Exception\DecodingException;
use NeoPHP\Component\HttpClient\Exception\HttpException;

class Response implements ResponseInterface
{
    public function __construct(protected int $status, protected array $headers = [], protected ?string $content = '', protected array $info = [])
    {
        $normalized = [];

        foreach ($headers as $name => $values) {
            foreach ((array) $values as $value) {
                $normalized[strtolower((string) $name)][] = (string) $value;
            }
        }

        $this->headers = $normalized;
    }

    public static function fromRaw(array $lines, ?string $content, array $info = []): static
    {
        $status = 0;
        $headers = [];

        foreach ($lines as $line) {
            $line = rtrim((string) $line, "\r\n");

            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $matches) === 1) {
                $status = (int) $matches[1];
                $headers = [];
                continue;
            }

            $position = strpos($line, ':');

            if ($position === false || $position === 0) {
                continue;
            }

            $headers[strtolower(trim(substr($line, 0, $position)))][] = trim(substr($line, $position + 1));
        }

        return new static($status, $headers, $content, $info);
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    public function getContent(bool $throw = true): string
    {
        if ($throw && $this->status >= 300) {
            throw HttpException::create($this);
        }

        if ($this->content === null) {
            $sink = (string) ($this->info['sink'] ?? '');

            return is_file($sink) ? (string) file_get_contents($sink) : '';
        }

        return $this->content;
    }

    public function toArray(bool $throw = true): array
    {
        $content = $this->getContent($throw);

        if (trim($content) === '') {
            throw new DecodingException('The response of "{url}" is empty and cannot be decoded as JSON.', 0, null, ['url' => (string) $this->getInfo('url')]);
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new DecodingException('The response of "{url}" is not valid JSON: {error}.', 0, $exception, [
                'url' => (string) $this->getInfo('url'),
                'error' => $exception->getMessage(),
            ]);
        }

        if (!is_array($data)) {
            throw new DecodingException('The JSON response of "{url}" is a {type}, an object or an array was expected.', 0, null, [
                'url' => (string) $this->getInfo('url'),
                'type' => get_debug_type($data),
            ]);
        }

        return $data;
    }

    public function getInfo(?string $key = null): mixed
    {
        return $key === null ? $this->info : ($this->info[$key] ?? null);
    }

    public function withInfo(array $info): static
    {
        $clone = clone $this;
        $clone->info = array_replace($this->info, $info);

        return $clone;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}