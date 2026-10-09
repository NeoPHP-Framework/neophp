<?php

declare(strict_types=1);

namespace NeoPHP\Component\Asset\Compiler;

interface CompilerInterface
{
    public function supports(string $extension): bool;

    public function compile(string $content, string $path, callable $resolve, bool $minify = false): string;
}