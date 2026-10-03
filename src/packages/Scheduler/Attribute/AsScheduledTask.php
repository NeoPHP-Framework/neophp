<?php

declare(strict_types=1);

namespace NeoPHP\Package\Scheduler\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class AsScheduledTask
{
    public function __construct(
        public string $cron = '* * * * *',
        public ?string $name = null,
        public ?string $timezone = null,
        public bool $withoutOverlapping = false,
        public int $overlapTtl = 1440,
        public string $description = '',
        public string $arguments = '',
    ) {
    }
}