<?php

declare(strict_types=1);

namespace NeoPHP\Package\Tailwind;

interface TailwindManagerInterface
{
    public const DEFAULT_INPUT = 'css/app.css';

    public const DEFAULT_VERSION = 'latest';

    public function getInput(): ?string;

    public function setInput(string $input): static;

    public function getVersion(): string;

    public function getBinary(): string;

    public function isInstalled(): bool;

    public function getInstalledVersion(): ?string;

    public function getPlatform(): string;

    public function getDownloadUrl(?string $version = null): string;

    public function install(?string $version = null, bool $force = false): array;

    public function getSourceFile(string $input): string;

    public function getOutputFile(string $input): string;

    public function initSource(string $input): string;

    public function getCommand(string $input, bool $watch = false, bool $minify = false): array;

    public function run(string $input, bool $watch = false, bool $minify = false): int;

    public function saveConfig(): string;
}