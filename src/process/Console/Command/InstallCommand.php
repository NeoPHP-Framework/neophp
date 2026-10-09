<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\Command;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Installer\InstallerManagerInterface;

/**
 * @internal
 */
#[AsCommand(name: 'install', description: 'Generates the project files (public/, src/Kernel.php, config/, templates/...)')]
class InstallCommand extends AbstractConsole
{
    public function __construct(protected InstallerManagerInterface $installer, protected ContainerManagerInterface $container)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $this->setHelp('Existing files are kept, except with --force. The missing variables of .env are always added.');
        $this->addExample('install');
        $this->addExample('install --force');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $rootPath = (string) $this->container->get('kernel.root_path');
        $report = $this->installer->install($rootPath, (bool) $input->getOption('force'));

        $output->title('Installing NeoPHP in ' . $rootPath);

        foreach ($report as $path => $status) {
            $style = match ($status) {
                InstallerManagerInterface::STATUS_CREATED => 'success',
                InstallerManagerInterface::STATUS_OVERWRITTEN, InstallerManagerInterface::STATUS_UPDATED => 'comment',
                default => 'muted',
            };

            $output->writeln(sprintf('  <%1$s>%2$s</%1$s>  %3$s', $style, str_pad($status, 11), $path), $status === InstallerManagerInterface::STATUS_SKIPPED ? OutputInterface::VERBOSITY_VERBOSE : OutputInterface::VERBOSITY_NORMAL);
        }

        if (($report['composer.json'] ?? null) === InstallerManagerInterface::STATUS_UPDATED) {
            $output->warning('composer.json was updated: run "composer dump-autoload".');
        }

        $output->success('Done. Start the server with: php bin/neo serve');

        return self::SUCCESS;
    }
}