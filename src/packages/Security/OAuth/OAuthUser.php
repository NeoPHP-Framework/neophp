<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\OAuth;

class OAuthUser
{
    public function __construct(
        public readonly string $provider,
        public readonly string $id,
        public readonly ?string $email = null,
        public readonly bool $emailVerified = false,
        public readonly ?string $name = null,
        public readonly array $raw = [],
    ) {
    }
}