<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\OpenApi\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Parameter
{
    public function __construct(
        public string $name,
        public string $in = 'query',
        public ?string $description = null,
        public bool $required = false,
        public string $type = 'string',
        public ?string $format = null,
        public ?array $enum = null,
        public mixed $example = null,
        public bool $deprecated = false,
        public ?array $schema = null,
    ) {
    }
}