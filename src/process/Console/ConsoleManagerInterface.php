<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console;

use NeoPHP\Process\Console\Contract\CommandInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;

interface ConsoleManagerInterface
{
    public function add(CommandInterface|string $command): static;

    public function all(): array;

    public function has(string $name): bool;

    public function resolveName(string $name): string;

    public function find(string $name): CommandInterface;

    public function getVersion(): string;

    public function run(array $argv, ?OutputInterface $output = null): int;
}