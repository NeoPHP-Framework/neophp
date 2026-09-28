<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\RememberMe;

class PersistentToken
{
    public function __construct(
        public readonly string $series,
        public readonly string $tokenHash,
        public readonly string $class,
        public readonly string $identifier,
        public readonly string $fingerprint,
        public readonly int $createdAt,
        public readonly int $lastUsedAt,
        public readonly int $expiresAt,
        public readonly ?string $userAgent = null,
        public readonly ?string $ip = null,
    ) {
    }

    public function isExpired(?int $now = null): bool
    {
        return $this->expiresAt <= ($now ?? time());
    }

    public function toArray(): array
    {
        return [
            'series' => $this->series,
            'created_at' => $this->createdAt,
            'last_used_at' => $this->lastUsedAt,
            'expires_at' => $this->expiresAt,
            'user_agent' => $this->userAgent,
            'ip' => $this->ip,
        ];
    }
}