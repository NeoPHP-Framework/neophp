<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Cors
{
    public function __construct(
        public ?array $allowOrigin = null,
        public ?array $allowMethods = null,
        public ?array $allowHeaders = null,
        public ?array $exposeHeaders = null,
        public ?bool $allowCredentials = null,
        public ?int $maxAge = null,
    ) {
    }

    public function toArray(): array
    {
        return array_filter([
            'allow_origin' => $this->allowOrigin,
            'allow_methods' => $this->allowMethods,
            'allow_headers' => $this->allowHeaders,
            'expose_headers' => $this->exposeHeaders,
            'allow_credentials' => $this->allowCredentials,
            'max_age' => $this->maxAge,
        ], static fn (mixed $value): bool => $value !== null);
    }
}