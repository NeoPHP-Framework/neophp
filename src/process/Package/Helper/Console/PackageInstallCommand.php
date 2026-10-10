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
#[AsCommand(name: 'neophp:package:install', description: 'Installs a NeoPHP package with Composer and copies its configuration into config/packages/')]
class PackageInstallCommand extends AbstractPackageCommand
{
    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('package', InputArgument::REQUIRED, 'The Composer name of the package, with an optional version constraint', null, 'Composer name of the package (e.g. acme/neo-billing)');
        $input->addOption('dev', null, InputOption::VALUE_NONE, 'Adds the package to the require-dev section of composer.json');
        $this->setHelp('Only the packages of Composer type "neophp-package" are accepted. The configuration files of the package are copied into config/packages/<name>/; an existing file is never replaced, except with --force.');
        $this->addExample('neophp:package:install acme/neo-billing');
        $this->addExample('neophp:package:install acme/neo-billing:^1.2');
        $this->addExample('neophp:package:install acme/neo-debug-tools --dev');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->packages->install((string) $input->getArgument('package'), (bool) $input->getOption('dev'), (bool) $input->getOption('force'));
        } catch (PackageException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $package = $result['package'];
        $output->title(sprintf('%s %s installed', $package['name'], $package['version']));
        $this->renderFiles($output, $result['files']);

        $details = ['Name' => $package['alias']];

        if ($result['files'] !== []) {
            $details['Configuration'] = 'config/packages/' . $package['alias'] . '/';
        }

        if ($package['templates'] !== null) {
            $details['Templates'] = '@' . $package['alias'] . '/... (override them in templates/packages/' . $package['alias'] . '/)';
        }

        if ($package['routes'] !== null) {
            $details['Routes'] = '@' . $package['alias'] . ' imported in config/routes.yaml';
        }

        if ($package['assets'] !== null) {
            $details['Assets'] = "asset('@" . $package['alias'] . "/...')";
        }

        if ($package['translations'] !== null) {
            $details['Translations'] = 'loaded from the package, overridden by translations/ of the project';
        }

        if ($package['modules'] !== []) {
            $details['Modules'] = implode(', ', $package['modules']);
        }

        $output->definitionList($details);

        if ($package['migrations'] !== null) {
            $output->note('The package has database migrations: run php bin/neo migration:migrate');
        }

        $output->success(sprintf('%s is ready. Read its README for the next steps: %s', $package['name'], $package['path'] . DIRECTORY_SEPARATOR . 'README.md'));

        return self::SUCCESS;
    }
}