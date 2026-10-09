<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\OpenApi\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Response
{
    public function __construct(
        public int $status = 200,
        public ?string $description = null,
        public ?string $type = null,
        public ?array $groups = null,
        public bool $isList = false,
        public bool $paginated = false,
        public ?string $contentType = null,
        public array $headers = [],
        public ?array $schema = null,
    ) {
    }
}