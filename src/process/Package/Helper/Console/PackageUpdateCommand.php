<?php

declare(strict_types=1);

namespace NeoPHP\Process\Package\Helper\Console;

use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Package\Exception\PackageException;

/**
 * @internal
 */
#[AsCommand(name: 'neophp:package:update', description: 'Updates the NeoPHP packages with Composer and copies their new configuration files')]
class PackageUpdateCommand extends AbstractPackageCommand
{
    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('package', InputArgument::OPTIONAL, 'The Composer name or the name of the package (all the NeoPHP packages by default)');
        $this->setHelp('A configuration file not changed in the project is updated; a file changed in the project is kept and the new version is written next to it with the .dist extension (--force overwrites it).');
        $this->addExample('neophp:package:update');
        $this->addExample('neophp:package:update acme/neo-billing');
        $this->addExample('neophp:package:update billing --force');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $package = $input->getArgument('package');

        try {
            $report = $this->packages->update(is_string($package) ? $package : null, (bool) $input->getOption('force'));
        } catch (PackageException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($report === []) {
            $output->note('No NeoPHP package is installed.');

            return self::SUCCESS;
        }

        foreach ($report as $name => $result) {
            $output->section(sprintf('%s %s', $name, $result['package']['version']));

            if ($result['files'] === []) {
                $output->writeln('  <muted>no configuration file</muted>');
                continue;
            }

            $this->renderFiles($output, $result['files']);
        }

        $output->success(sprintf('%d NeoPHP package(s) updated.', count($report)));

        return self::SUCCESS;
    }
}