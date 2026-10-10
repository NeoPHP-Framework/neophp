<?php

declare(strict_types=1);

namespace NeoPHP\Process\Package\Helper\Console;

use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\IO\InputOption;
use NeoPHP\Process\Package\Exception\PackageException;

/**
 * @internal
 */
#[AsCommand(name: 'neophp:package:remove', description: 'Removes a NeoPHP package with Composer')]
class PackageRemoveCommand extends AbstractPackageCommand
{
    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('package', InputArgument::REQUIRED, 'The Composer name or the name of the package', null, 'Composer name of the package to remove');
        $input->addOption('purge', null, InputOption::VALUE_NONE, 'Also deletes its configuration directory config/packages/<name>/');
        $this->setHelp('The entries of the package in config/config.php are removed. Its configuration files are kept, except with --purge.');
        $this->addExample('neophp:package:remove acme/neo-billing');
        $this->addExample('neophp:package:remove billing --purge');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('package');
        $installed = $this->packages->find($name);

        if ($installed === null) {
            $output->error(sprintf('The NeoPHP package "%s" is not installed (see neophp:package:list).', $name));

            return self::FAILURE;
        }

        $purge = (bool) $input->getOption('purge');

        if ($output->isInteractive() && !$output->confirm(sprintf('Remove %s%s?', $installed['name'], $purge ? ' and its configuration' : ''), true)) {
            $output->note('Nothing was removed.');

            return self::SUCCESS;
        }

        try {
            $result = $this->packages->remove($installed['name'], $purge);
        } catch (PackageException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->renderFiles($output, $result['files']);

        if (!$purge && is_dir($this->packages->getConfigDirectory($installed['alias']))) {
            $output->note(sprintf('The configuration is kept in config/packages/%s/ (--purge deletes it).', $installed['alias']));
        }

        $output->success(sprintf('%s removed.', $installed['name']));

        return self::SUCCESS;
    }
}