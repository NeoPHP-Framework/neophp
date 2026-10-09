<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Contract;

interface LoaderInterface
{
    public function load(string $file): array;

    public function getExtensions(): array;
}