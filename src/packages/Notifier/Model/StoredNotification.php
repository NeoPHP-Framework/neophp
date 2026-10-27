<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Model;

use DateTimeImmutable;

class StoredNotification
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        protected int $id,
        protected string $recipient,
        protected string $subject,
        protected string $content,
        protected ?string $url,
        protected ?string $type,
        protected string $importance,
        protected array $data,
        protected DateTimeImmutable $createdAt,
        protected ?DateTimeImmutable $readAt,
    ) {
    }

    public static function fromRow(array $row): static
    {
        $data = json_decode((string) ($row['data'] ?? ''), true);

        return new static(
            (int) $row['id'],
            (string) $row['recipient'],
            (string) $row['subject'],
            (string) ($row['content'] ?? ''),
            isset($row['url']) && $row['url'] !== '' ? (string) $row['url'] : null,
            isset($row['type']) && $row['type'] !== '' ? (string) $row['type'] : null,
            (string) ($row['importance'] ?? Notification::IMPORTANCE_NORMAL),
            is_array($data) ? $data : [],
            (new DateTimeImmutable())->setTimestamp((int) $row['created_at']),
            isset($row['read_at']) ? (new DateTimeImmutable())->setTimestamp((int) $row['read_at']) : null,
        );
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getRecipient(): string
    {
        return $this->recipient;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function getImportance(): string
    {
        return $this->importance;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }
}