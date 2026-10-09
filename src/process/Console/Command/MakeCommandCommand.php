<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\Command;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\Exception\ConsoleException;
use NeoPHP\Process\Console\Exception\InvalidInputException;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\Maker\CommandMaker;

/**
 * @internal
 */
#[AsCommand(name: 'make:command', description: 'Generates a console command in src/Command/')]
class MakeCommandCommand extends AbstractConsole
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('class', InputArgument::REQUIRED, 'The class name (SendReport, Admin\CleanUsers)', null, 'Class name of the command (e.g. SendReport)');
        $input->addArgument('command', InputArgument::OPTIONAL, 'The command name (app:send-report by default)');
        $this->addExample('make:command SendReport');
        $this->addExample('make:command Admin/CleanUsers admin:clean-users --force');
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $maker = new CommandMaker('');

        if (!$input->isArgumentProvided('class')) {
            $input->setArgument('class', $output->ask('Class name of the command (e.g. SendReport)', null, static function (mixed $value) use ($maker): string {
                try {
                    return $maker->resolve(trim((string) $value))[2];
                } catch (ConsoleException $exception) {
                    throw new InvalidInputException($exception->getMessage());
                }
            }));
        }

        if (!$input->isArgumentProvided('command')) {
            $input->setArgument('command', $output->ask('Command name', $maker->commandName($maker->resolve((string) $input->getArgument('class'))[2])));
        }
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $root = (string) $this->container->get('kernel.root_path');
        $maker = new CommandMaker($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Command');
        $name = $input->getArgument('command');

        try {
            [$class, $file, $commandName] = $maker->make((string) $input->getArgument('class'), is_string($name) && $name !== '' ? $name : null, (bool) $input->getOption('force'));
        } catch (ConsoleException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln(sprintf('  <success>created</success>  %s', $file));
        $output->success(sprintf('%s is available: php bin/neo %s', $class, $commandName));

        return self::SUCCESS;
    }
}