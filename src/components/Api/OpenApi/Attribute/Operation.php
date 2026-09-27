<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\OpenApi\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class Operation
{
    public function __construct(
        public ?string $summary = null,
        public ?string $description = null,
        public array $tags = [],
        public bool $deprecated = false,
        public ?string $operationId = null,
        public bool $hidden = false,
        public array $security = [],
    ) {
    }
}