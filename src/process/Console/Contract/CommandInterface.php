<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\Contract;

use NeoPHP\Process\Console\Exception\InvalidInputException;
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

    /**
     * Runs the command: binds the input to the definition of the command, then executes it.
     *
     * @param InputInterface $input The input of the command line
     * @param OutputInterface $output The output
     * @return int The exit code: SUCCESS, FAILURE or INVALID
     * @throws InvalidInputException When an argument is missing, or an option does not exist, does not accept a value or requires one
     */
    public function run(InputInterface $input, OutputInterface $output): int;
}