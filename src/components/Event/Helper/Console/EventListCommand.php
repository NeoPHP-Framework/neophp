<?php

declare(strict_types=1);

namespace NeoPHP\Component\Event\Helper\Console;

use NeoPHP\Component\Event\EventManager;
use NeoPHP\Component\Event\EventManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;

/**
 * @internal
 */
#[AsCommand(name: 'event:list', description: 'Lists the events and their listeners in the order they are called')]
class EventListCommand extends AbstractConsole
{
    public function __construct(protected EventManagerInterface $events)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('filter', InputArgument::OPTIONAL, 'Only show the events whose name contains this text');
        $this->addExample('event:list');
        $this->addExample('event:list Response');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $filter = (string) ($input->getArgument('filter') ?? '');
        $rows = [];

        foreach ($this->events->getListeners() as $event => $listeners) {
            if ($filter !== '' && stripos((string) $event, $filter) === false) {
                continue;
            }

            foreach ($listeners as $index => [$listener, $priority]) {
                $rows[] = [$index === 0 ? (string) $event : '', (string) ($index + 1), EventManager::describe($listener), (string) $priority];
            }
        }

        if ($rows === []) {
            $output->note($filter === '' ? 'No listener found.' : 'No event matches "' . $filter . '".');

            return self::SUCCESS;
        }

        $output->table(['Event', '#', 'Listener', 'Priority'], $rows);

        return self::SUCCESS;
    }
}