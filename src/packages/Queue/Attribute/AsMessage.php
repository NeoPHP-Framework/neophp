<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class AsMessage
{
    public function __construct(
        public ?string $transport = null,
        public ?string $queue = null,
        public ?int $priority = null,
        public ?int $maxAttempts = null,
    ) {
    }
}