<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Message;

class Envelope
{
    protected ?string $id = null;

    protected string $transport = '';

    protected string $queue = 'default';

    protected int $priority = 0;

    protected int $maxAttempts = 3;

    protected int $attempts = 0;

    protected int $availableAt;

    protected int $createdAt;

    protected ?int $reservedAt = null;

    protected ?int $failedAt = null;

    protected ?string $lastError = null;

    protected ?string $messageClass = null;

    protected array $meta = [];

    public function __construct(protected ?object $message = null)
    {
        $this->createdAt = time();
        $this->availableAt = $this->createdAt;
        $this->messageClass = $message !== null ? $message::class : null;
    }

    public function getMessage(): ?object
    {
        return $this->message;
    }

    public function setMessage(?object $message): static
    {
        $this->message = $message;
        $this->messageClass = $message !== null ? $message::class : $this->messageClass;

        return $this;
    }

    public function getMessageClass(): string
    {
        return $this->messageClass ?? 'unknown';
    }

    public function setMessageClass(string $class): static
    {
        $this->messageClass = $class;

        return $this;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(?string $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getTransport(): string
    {
        return $this->transport;
    }

    public function setTransport(string $transport): static
    {
        $this->transport = $transport;

        return $this;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function setQueue(string $queue): static
    {
        $this->queue = $queue;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function setMaxAttempts(int $maxAttempts): static
    {
        $this->maxAttempts = max(1, $maxAttempts);

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): static
    {
        $this->attempts = max(0, $attempts);

        return $this;
    }

    public function getAvailableAt(): int
    {
        return $this->availableAt;
    }

    public function setAvailableAt(int $availableAt): static
    {
        $this->availableAt = $availableAt;

        return $this;
    }

    public function getDelay(): int
    {
        return max(0, $this->availableAt - $this->createdAt);
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    public function setCreatedAt(int $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getReservedAt(): ?int
    {
        return $this->reservedAt;
    }

    public function setReservedAt(?int $reservedAt): static
    {
        $this->reservedAt = $reservedAt;

        return $this;
    }

    public function getFailedAt(): ?int
    {
        return $this->failedAt;
    }

    public function setFailedAt(?int $failedAt): static
    {
        $this->failedAt = $failedAt;

        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): static
    {
        $this->lastError = $lastError;

        return $this;
    }

    public function hasAttemptsLeft(): bool
    {
        return $this->attempts < $this->maxAttempts;
    }

    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    public function setMeta(string $key, mixed $value): static
    {
        $this->meta[$key] = $value;

        return $this;
    }
}