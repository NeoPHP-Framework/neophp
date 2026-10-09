<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\Command;

use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\ConsoleManagerInterface;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\Exception\CommandNotFoundException;
use NeoPHP\Process\Console\IO\HelpRenderer;
use NeoPHP\Process\Console\IO\InputArgument;

/**
 * @internal
 */
#[AsCommand(name: 'list', description: 'Lists the commands, grouped by namespace')]
class ListCommand extends AbstractConsole
{
    public function __construct(protected ConsoleManagerInterface $console)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('namespace', InputArgument::OPTIONAL, 'Only list the commands of this namespace (make, database...)');
        $this->addExample('list');
        $this->addExample('list make');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $namespace = $input->getArgument('namespace');
        $namespace = is_string($namespace) && $namespace !== '' ? $namespace : null;
        $commands = $this->console->all();

        if ($namespace !== null) {
            $namespaces = [];

            foreach (array_keys($commands) as $name) {
                if (str_contains((string) $name, ':')) {
                    $namespaces[strstr((string) $name, ':', true)] = true;
                }
            }

            if (!isset($namespaces[$namespace])) {
                $alternatives = array_values(array_filter(array_keys($namespaces), static fn (string $candidate): bool => levenshtein($namespace, $candidate) <= max(1, intdiv(strlen($namespace), 3)) || str_starts_with($candidate, $namespace)));

                throw new CommandNotFoundException('There are no commands defined in the "{namespace}" namespace.', $alternatives, ['namespace' => $namespace]);
            }
        }

        (new HelpRenderer())->renderList($commands, $output, $this->console->getVersion(), $namespace);

        return self::SUCCESS;
    }
}