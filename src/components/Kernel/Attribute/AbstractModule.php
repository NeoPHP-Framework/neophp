<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Attribute;

abstract class AbstractModule
{
    public const TYPE = 'module';

    public function __construct(
        public readonly string $provider,
        public readonly array $requires = [],
    ) {
    }

    public function getType(): string
    {
        return static::TYPE;
    }
}