<?php

declare(strict_types=1);

namespace NeoPHP\Component\Asset;

use NeoPHP\Component\Asset\Compiler\CompilerInterface;
use NeoPHP\Component\Asset\Manifest\Manifest;

interface AssetManagerInterface
{
    public function url(string $path): string;

    public function compile(string $path): string;

    public function reload(bool $minify = false): array;

    public function clear(): void;

    public function addCompiler(CompilerInterface $compiler): static;

    public function setSourceFile(string $path, ?string $file): static;

    public function getSourceFile(string $path): string;

    public function addNamespace(string $name, string $directory): static;

    public function getNamespaces(): array;

    public function getSourcePath(): string;

    public function getBuildPath(): string;

    public function getManifest(): Manifest;
}