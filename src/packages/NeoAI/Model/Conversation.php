<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Model;

class Conversation
{
    protected array $messages = [];

    public function __construct(protected string $id, array $messages = [], protected array $meta = [])
    {
        foreach ($messages as $message) {
            $this->add($message instanceof Message ? $message : Message::fromArray((array) $message));
        }

        $this->meta += ['created_at' => time(), 'updated_at' => time()];
    }

    public static function create(array $meta = []): static
    {
        return new static(bin2hex(random_bytes(12)), [], $meta);
    }

    public static function fromArray(array $data): static
    {
        return new static((string) ($data['id'] ?? bin2hex(random_bytes(12))), (array) ($data['messages'] ?? []), (array) ($data['meta'] ?? []));
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function add(Message $message): static
    {
        $this->messages[] = $message;
        $this->meta['updated_at'] = time();

        return $this;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getLastMessages(int $limit): array
    {
        return $limit > 0 ? array_slice($this->messages, -$limit) : $this->messages;
    }

    public function count(): int
    {
        return count($this->messages);
    }

    public function clear(): static
    {
        $this->messages = [];

        return $this;
    }

    public function getMeta(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->meta : ($this->meta[$key] ?? $default);
    }

    public function setMeta(string $key, mixed $value): static
    {
        $this->meta[$key] = $value;

        return $this;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'meta' => $this->meta,
            'messages' => array_map(static fn (Message $message): array => $message->toArray(), $this->messages),
        ];
    }
}