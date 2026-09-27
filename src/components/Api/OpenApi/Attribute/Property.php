<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\OpenApi\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER)]
class Property
{
    public function __construct(
        public ?string $description = null,
        public mixed $example = null,
        public ?string $format = null,
        public bool $deprecated = false,
        public ?bool $required = null,
        public ?array $schema = null,
    ) {
    }
}