<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Model;

class Profile
{
    public const SUMMARY_KEYS = ['token', 'ip', 'method', 'url', 'status', 'time', 'duration', 'memory', 'route', 'content_type'];

    protected array $data = [];

    public function __construct(
        protected string $token,
        protected string $ip = '',
        protected string $method = 'GET',
        protected string $url = '',
        protected int $status = 200,
        protected int $time = 0,
        protected float $duration = 0.0,
        protected int $memory = 0,
        protected ?string $route = null,
        protected ?string $contentType = null,
    ) {
        $this->time = $time > 0 ? $time : time();
    }

    public static function fromArray(array $data): static
    {
        $profile = new static(
            (string) ($data['token'] ?? ''),
            (string) ($data['ip'] ?? ''),
            (string) ($data['method'] ?? 'GET'),
            (string) ($data['url'] ?? ''),
            (int) ($data['status'] ?? 200),
            (int) ($data['time'] ?? 0),
            (float) ($data['duration'] ?? 0),
            (int) ($data['memory'] ?? 0),
            isset($data['route']) ? (string) $data['route'] : null,
            isset($data['content_type']) ? (string) $data['content_type'] : null,
        );

        foreach ((array) ($data['data'] ?? []) as $name => $collected) {
            $profile->setData((string) $name, (array) $collected);
        }

        return $profile;
    }

    public function toArray(): array
    {
        return [...$this->getSummary(), 'data' => $this->data];
    }

    public function getSummary(): array
    {
        return [
            'token' => $this->token,
            'ip' => $this->ip,
            'method' => $this->method,
            'url' => $this->url,
            'status' => $this->status,
            'time' => $this->time,
            'duration' => $this->duration,
            'memory' => $this->memory,
            'route' => $this->route,
            'content_type' => $this->contentType,
        ];
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getTime(): int
    {
        return $this->time;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public function setDuration(float $duration): static
    {
        $this->duration = $duration;

        return $this;
    }

    public function getMemory(): int
    {
        return $this->memory;
    }

    public function setMemory(int $memory): static
    {
        $this->memory = $memory;

        return $this;
    }

    public function getRoute(): ?string
    {
        return $this->route;
    }

    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    public function getData(?string $name = null): array
    {
        return $name === null ? $this->data : (array) ($this->data[$name] ?? []);
    }

    public function hasData(string $name): bool
    {
        return array_key_exists($name, $this->data);
    }

    public function setData(string $name, array $data): static
    {
        $this->data[$name] = $data;

        return $this;
    }
}