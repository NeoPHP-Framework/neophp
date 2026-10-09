<?php

declare(strict_types=1);

namespace NeoPHP\Package\Debug\Contract;

interface DumperInterface
{
    public function dump(array $node, ?string $label = null): string;
}