<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Attribute;

use Attribute;
use NeoPHP\Component\Middleware\Attribute\Middleware;
use NeoPHP\Package\Security\Middleware\IsGrantedMiddleware;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class IsGranted extends Middleware
{
    public function __construct(
        public string|array $attribute,
        public string|array|null $subject = null,
        public ?string $message = null,
        public ?int $statusCode = null,
    ) {
        parent::__construct(IsGrantedMiddleware::class);
    }
}