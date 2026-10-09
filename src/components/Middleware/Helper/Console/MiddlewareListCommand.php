<?php

declare(strict_types=1);

namespace NeoPHP\Component\Middleware\Helper\Console;

use NeoPHP\Component\Middleware\MiddlewareManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;

/**
 * @internal
 */
#[AsCommand(name: 'middleware:list', description: 'Lists the global middlewares, the aliases and the groups')]
class MiddlewareListCommand extends AbstractConsole
{
    public function __construct(protected MiddlewareManagerInterface $middlewares)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $this->addExample('middleware:list');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];

        foreach ($this->middlewares->getGlobal() as $index => $class) {
            $rows[] = ['global', (string) ($index + 1), $class];
        }

        foreach ($this->middlewares->getAliases() as $name => $class) {
            $rows[] = ['alias', (string) $name, $class];
        }

        foreach ($this->middlewares->getGroups() as $name => $items) {
            $rows[] = ['group', (string) $name, implode(', ', $items)];
        }

        if ($rows === []) {
            $output->note('No middleware defined.');

            return self::SUCCESS;
        }

        $output->table(['Type', 'Name', 'Middleware'], $rows);

        return self::SUCCESS;
    }
}