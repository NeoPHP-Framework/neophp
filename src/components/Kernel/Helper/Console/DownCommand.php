<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Helper\Console;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Kernel\Maintenance\MaintenanceMode;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputOption;

/**
 * @internal
 */
#[AsCommand(name: 'down', description: 'Enables the maintenance mode: every request gets a 503 response')]
class DownCommand extends AbstractConsole
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('retry', null, InputOption::VALUE_REQUIRED, 'Seconds of the Retry-After header', (string) MaintenanceMode::DEFAULT_RETRY);
        $input->addOption('message', null, InputOption::VALUE_REQUIRED, 'Message of the maintenance page');
        $input->addOption('allow', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'IP or CIDR range that still reaches the application');
        $this->addExample('down');
        $this->addExample('down --retry=300 --message="Back in 5 minutes" --allow=203.0.113.10');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $maintenance = new MaintenanceMode((string) $this->container->get('kernel.root_path'));
        $maintenance->enable((int) $input->getOption('retry'), array_values(array_map('strval', (array) $input->getOption('allow'))), (string) $input->getOption('message'));
        $output->success('The application is in maintenance mode. Run "up" to disable it.');

        return self::SUCCESS;
    }
}