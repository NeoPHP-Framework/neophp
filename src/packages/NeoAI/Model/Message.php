<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Model;

class Message
{
    public const SYSTEM = 'system';

    public const USER = 'user';

    public const ASSISTANT = 'assistant';

    public const ROLES = [self::SYSTEM, self::USER, self::ASSISTANT];

    public function __construct(protected string $role, protected string $content)
    {
        $this->role = in_array($role, self::ROLES, true) ? $role : self::USER;
    }

    public static function system(string $content): static
    {
        return new static(self::SYSTEM, $content);
    }

    public static function user(string $content): static
    {
        return new static(self::USER, $content);
    }

    public static function assistant(string $content): static
    {
        return new static(self::ASSISTANT, $content);
    }

    public static function fromArray(array $data): static
    {
        return new static((string) ($data['role'] ?? self::USER), (string) ($data['content'] ?? ''));
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function withContent(string $content): static
    {
        return new static($this->role, $content);
    }

    public function toArray(): array
    {
        return ['role' => $this->role, 'content' => $this->content];
    }
}