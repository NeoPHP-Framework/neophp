<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Event;

use NeoPHP\Component\Event\Contract\AbstractEvent;

class RequestEvent extends AbstractEvent
{
    public function __construct(protected string $method, protected string $url, protected array $options = [])
    {
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): void
    {
        $this->method = strtoupper($method);
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    public function setOption(string $name, mixed $value): void
    {
        $this->options[$name] = $value;
    }

    public function setHeader(string $name, string $value): void
    {
        $headers = (array) ($this->options['headers'] ?? []);

        foreach (array_keys($headers) as $key) {
            if (strcasecmp((string) $key, $name) === 0) {
                unset($headers[$key]);
            }
        }

        $headers[$name] = $value;
        $this->options['headers'] = $headers;
    }
}