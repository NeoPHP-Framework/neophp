<?php

declare(strict_types=1);

namespace NeoPHP\Component\Csrf\Attribute;

use Attribute;
use NeoPHP\Component\Csrf\Middleware\CsrfMiddleware;
use NeoPHP\Component\Middleware\Attribute\Middleware;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Csrf extends Middleware
{
    public const METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        public string $id,
        public ?string $field = null,
        public ?string $header = null,
        public array $methods = self::METHODS,
    ) {
        parent::__construct(CsrfMiddleware::class);
    }
}