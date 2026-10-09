<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\Contract;

use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\IO\InputDefinition;
use NeoPHP\Process\Console\IO\InputOption;

interface InputInterface
{
    public function addArgument(string $name, int $mode = InputArgument::OPTIONAL, string $description = '', mixed $default = null, ?string $question = null): static;

    public function addOption(string $name, ?string $shortcut = null, int $mode = InputOption::VALUE_NONE, string $description = '', mixed $default = null, ?string $question = null): static;

    public function getDefinition(): InputDefinition;

    public function bind(): static;

    public function validate(): static;

    public function getTokens(): array;

    public function hasParameterOption(string|array $names): bool;

    public function getArgument(string $name): mixed;

    public function getArguments(): array;

    public function hasArgument(string $name): bool;

    public function isArgumentProvided(string $name): bool;

    public function setArgument(string $name, mixed $value): static;

    public function getOption(string $name): mixed;

    public function getOptions(): array;

    public function hasOption(string $name): bool;

    public function isOptionProvided(string $name): bool;

    public function setOption(string $name, mixed $value): static;

    public function getOptionCount(string $name): int;

    public function isInteractive(): bool;

    public function setInteractive(bool $interactive): static;
}