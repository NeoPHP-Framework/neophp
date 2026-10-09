<?php

declare(strict_types=1);

namespace NeoPHP\Component\Routing\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Route
{
    public function __construct(
        public string $path = '',
        public ?string $name = null,
        public array|string $methods = [],
        public array $requirements = [],
        public array $defaults = [],
        public array $options = [],
        public array $middlewares = [],
    ) {
    }

    public function getMethods(): array
    {
        $methods = is_string($this->methods) ? (preg_split('/\s*[|,]\s*/', trim($this->methods), -1, PREG_SPLIT_NO_EMPTY) ?: []) : $this->methods;

        return array_values(array_map(static fn (mixed $method): string => strtoupper((string) $method), $methods));
    }
}