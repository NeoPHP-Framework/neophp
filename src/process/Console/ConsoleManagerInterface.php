<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console;

use NeoPHP\Component\Container\Exception\ContainerException;
use NeoPHP\Process\Console\Contract\CommandInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\Exception\CommandNotFoundException;
use NeoPHP\Process\Console\Exception\ConsoleException;

interface ConsoleManagerInterface
{
    /**
     * Registers a command and its aliases; a command class is built on first use.
     *
     * @param CommandInterface|string $command The command, or its class declared with #[AsCommand]
     * @return static The console manager
     * @throws ConsoleException When the class does not exist, does not implement CommandInterface or has no #[AsCommand] attribute
     */
    public function add(CommandInterface|string $command): static;

    /**
     * Returns the registered commands, sorted by name.
     *
     * @return array<string, array<string, mixed>> The metadata of every command (class, name, description, aliases, hidden...), by name
     */
    public function all(): array;

    /**
     * Tells whether a command or an alias is registered.
     *
     * @param string $name Name or alias of the command
     * @return bool True when the command exists
     */
    public function has(string $name): bool;

    /**
     * Returns the full name of a command from its name, an alias or an abbreviation (c:c for cache:clear).
     *
     * @param string $name Name, alias or abbreviation
     * @return string The name of the command
     * @throws CommandNotFoundException When no command matches or the abbreviation is ambiguous
     */
    public function resolveName(string $name): string;

    /**
     * Returns a command, built by the container on first use.
     *
     * @param string $name Name, alias or abbreviation
     * @return CommandInterface The command
     * @throws CommandNotFoundException When no command matches or the abbreviation is ambiguous
     * @throws ConsoleException When the built object does not implement CommandInterface
     * @throws ContainerException When the command cannot be built by the container
     */
    public function find(string $name): CommandInterface;

    /**
     * Returns the version shown by --version and the list of commands.
     *
     * @return string The version, empty when unknown
     */
    public function getVersion(): string;

    /**
     * Runs the command of the command line; every error is rendered on the output.
     *
     * @param array<string> $argv The command line, the script name first
     * @param OutputInterface|null $output The output, the console when null
     * @return int The exit code: CommandInterface::SUCCESS, FAILURE or INVALID
     */
    public function run(array $argv, ?OutputInterface $output = null): int;
}