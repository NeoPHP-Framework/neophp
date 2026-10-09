<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\Contract;

use NeoPHP\Process\Console\IO\InputDefinition;

interface CommandInterface
{
    public const SUCCESS = 0;

    public const FAILURE = 1;

    public const INVALID = 2;

    public function getName(): string;

    public function getDescription(): string;

    public function getAliases(): array;

    public function isHidden(): bool;

    public function getHelp(): ?string;

    public function getExamples(): array;

    public function getDefinition(OutputInterface $output): InputDefinition;

    public function run(InputInterface $input, OutputInterface $output): int;
}