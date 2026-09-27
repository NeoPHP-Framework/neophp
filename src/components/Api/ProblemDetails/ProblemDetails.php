<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\ProblemDetails;

use JsonSerializable;
use NeoPHP\Component\Http\Response\JsonResponse;
use NeoPHP\Component\Http\Response\Response;

class ProblemDetails implements JsonSerializable
{
    public const CONTENT_TYPE = 'application/problem+json';

    public const MEMBERS = ['type', 'title', 'status', 'detail', 'instance'];

    public function __construct(
        protected int $status = 500,
        protected ?string $title = null,
        protected ?string $detail = null,
        protected string $type = 'about:blank',
        protected ?string $instance = null,
        protected array $extensions = [],
        protected array $headers = [],
    ) {
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

    public function getTitle(): string
    {
        return $this->title ?? (Response::PHRASES[$this->status] ?? 'Error');
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }

    public function setDetail(?string $detail): static
    {
        $this->detail = $detail;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getInstance(): ?string
    {
        return $this->instance;
    }

    public function setInstance(?string $instance): static
    {
        $this->instance = $instance;

        return $this;
    }

    public function getExtensions(): array
    {
        return $this->extensions;
    }

    public function getExtension(string $name, mixed $default = null): mixed
    {
        return $this->extensions[$name] ?? $default;
    }

    public function setExtension(string $name, mixed $value): static
    {
        if (!in_array($name, self::MEMBERS, true)) {
            $this->extensions[$name] = $value;
        }

        return $this;
    }

    public function removeExtension(string $name): static
    {
        unset($this->extensions[$name]);

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

    public function toArray(): array
    {
        $data = [
            'type' => $this->type,
            'title' => $this->getTitle(),
            'status' => $this->status,
        ];

        if ($this->detail !== null && $this->detail !== '') {
            $data['detail'] = $this->detail;
        }

        if ($this->instance !== null) {
            $data['instance'] = $this->instance;
        }

        return [...$data, ...array_diff_key($this->extensions, array_flip(self::MEMBERS))];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toResponse(array $headers = []): JsonResponse
    {
        $response = new JsonResponse($this->toArray(), $this->status, [...$this->headers, ...$headers]);
        $response->headers->set('Content-Type', self::CONTENT_TYPE);

        return $response;
    }
}