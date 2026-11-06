<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Helper\Console;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Kernel\Maintenance\MaintenanceMode;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;

/**
 * @internal
 */
#[AsCommand(name: 'up', description: 'Disables the maintenance mode')]
class UpCommand extends AbstractConsole
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $maintenance = new MaintenanceMode((string) $this->container->get('kernel.root_path'));

        if (!$maintenance->disable()) {
            $output->error(sprintf('Unable to remove %s.', $maintenance->getFile()));

            return self::FAILURE;
        }

        $output->success('The maintenance mode is disabled.');

        return self::SUCCESS;
    }
}