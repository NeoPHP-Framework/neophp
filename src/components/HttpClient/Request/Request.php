<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Request;

class Request
{
    public function __construct(protected string $method, protected string $url, protected array $headers = [], protected string $body = '', protected array $options = [])
    {
        $this->method = strtoupper($method);
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function getHeaderLines(): array
    {
        $lines = [];

        foreach ($this->headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getOption(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public function with(string $method, string $url, array $headers, string $body): static
    {
        return new static($method, $url, $headers, $body, $this->options);
    }
}