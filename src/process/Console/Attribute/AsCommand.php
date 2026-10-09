<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class AsCommand
{
    public function __construct(
        public string $name,
        public string $description = '',
        public array $aliases = [],
        public bool $hidden = false,
        public ?string $help = null,
    ) {
    }
}