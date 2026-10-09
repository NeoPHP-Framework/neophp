<?php

declare(strict_types=1);

namespace NeoPHP\Component\Service\Helper\Console;

use NeoPHP\Component\Service\ServiceManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;

/**
 * @internal
 */
#[AsCommand(name: 'service:list', description: 'Lists the services of config/services.yaml, the aliases and the interfaces bound automatically')]
class ServiceListCommand extends AbstractConsole
{
    public function __construct(protected ServiceManagerInterface $services)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('filter', InputArgument::OPTIONAL, 'Only show the services whose id or class contains this text');
        $this->addExample('service:list');
        $this->addExample('service:list Repository');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $filter = (string) ($input->getArgument('filter') ?? '');
        $rows = [];

        foreach ($this->services->getServices() as $id => $definition) {
            $rows[] = [(string) $id, $definition['factory'] !== null ? 'factory' : (string) $definition['class'], $definition['shared'] ? 'yes' : 'no', (string) $definition['source']];
        }

        foreach ($this->services->getAliases() as $alias => $target) {
            $rows[] = [(string) $alias, '@' . $target, '', 'alias'];
        }

        foreach ($this->services->getInterfaces() as $interface => $target) {
            $rows[] = [(string) $interface, is_array($target) ? 'ambiguous: ' . implode(', ', $target) : '@' . $target, '', 'interface'];
        }

        if ($filter !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => stripos($row[0] . ' ' . $row[1], $filter) !== false));
        }

        if ($rows === []) {
            $output->note($filter === '' ? 'No service found.' : 'No service matches "' . $filter . '".');

            return self::SUCCESS;
        }

        $output->table(['Id', 'Class', 'Shared', 'Source'], $rows);

        return self::SUCCESS;
    }
}