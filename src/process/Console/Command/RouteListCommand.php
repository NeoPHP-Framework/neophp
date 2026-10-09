<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\Command;

use NeoPHP\Component\Routing\RoutingManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;

/**
 * @internal
 */
#[AsCommand(name: 'route:list', description: 'Lists the application routes', aliases: ['routes'])]
class RouteListCommand extends AbstractConsole
{
    public function __construct(protected RoutingManagerInterface $routing)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('filter', InputArgument::OPTIONAL, 'Only show the routes whose name, path or controller contains this text');
        $this->addExample('route:list');
        $this->addExample('route:list admin');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $filter = (string) ($input->getArgument('filter') ?? '');
        $rows = [];

        foreach ($this->routing->getRoutes() as $route) {
            $controller = $route->getController();
            $row = [
                $route->getName(),
                $route->getMethods() === [] ? 'ANY' : implode('|', $route->getMethods()),
                $route->getPath(),
                is_array($controller) ? implode('::', array_map('strval', $controller)) : (is_string($controller) ? $controller : get_debug_type($controller)),
            ];

            if ($filter === '' || stripos(implode(' ', $row), $filter) !== false) {
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            $output->note($filter === '' ? 'No route defined in config/routes.yaml.' : 'No route matches "' . $filter . '".');

            return self::SUCCESS;
        }

        $output->table(['Name', 'Method', 'Path', 'Controller'], $rows);

        return self::SUCCESS;
    }
}