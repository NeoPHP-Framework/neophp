<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\Command;

use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\ConsoleManagerInterface;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\HelpRenderer;
use NeoPHP\Process\Console\IO\InputArgument;

/**
 * @internal
 */
#[AsCommand(name: 'help', description: 'Displays the help of a command')]
class HelpCommand extends AbstractConsole
{
    public function __construct(protected ConsoleManagerInterface $console)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('command_name', InputArgument::OPTIONAL, 'The command name', 'help');
        $this->addExample('help make:entity');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $command = $this->console->find((string) $input->getArgument('command_name'));
        (new HelpRenderer())->render($command, $command->getDefinition($output), $output);

        return self::SUCCESS;
    }
}