<?php

declare(strict_types=1);

namespace NeoPHP\Process\Package\Helper\Console;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\IO\InputOption;
use NeoPHP\Process\Package\Exception\PackageException;
use NeoPHP\Process\Package\Maker\PackageMaker;
use NeoPHP\Process\Package\PackageManagerInterface;

/**
 * @internal
 */
#[AsCommand(name: 'neophp:package:create', description: 'Generates the skeleton of a NeoPHP package (composer.json, README, src/, config/, templates/)')]
class PackageCreateCommand extends AbstractPackageCommand
{
    public const DIRECTORY = 'packages';

    public function __construct(PackageManagerInterface $packages, protected ContainerManagerInterface $container)
    {
        parent::__construct($packages);
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('package', InputArgument::REQUIRED, 'The Composer name of the package (vendor/name)', null, 'Composer name of the package (e.g. acme/neo-billing)');
        $input->addOption('path', null, InputOption::VALUE_REQUIRED, 'The directory of the package (packages/<name> by default)');
        $input->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'The PHP namespace of the package (Vendor\Name by default)');
        $input->addOption('description', null, InputOption::VALUE_REQUIRED, 'The description of the package');
        $input->addOption('link', null, InputOption::VALUE_NONE, 'Adds the package as a Composer path repository of the project and installs it');
        $this->setHelp('The package is a module of type "neophp-package": a final manager declared with #[Package], its provider, a configuration file copied into config/packages/<name>/ on install, templates rendered with @<name>/ and a console command.');
        $this->addExample('neophp:package:create acme/neo-billing');
        $this->addExample('neophp:package:create acme/neo-billing --link');
        $this->addExample('neophp:package:create acme/neo-billing --path=../neo-billing --namespace="Acme\Billing"');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $rootPath = (string) $this->container->get('kernel.root_path');
        $package = strtolower(trim((string) $input->getArgument('package')));
        $maker = new PackageMaker();

        try {
            $variables = $maker->variables(
                $package,
                $this->stringOption($input, 'namespace'),
                $this->stringOption($input, 'description'),
                $this->container->has('kernel.version') ? (string) $this->container->get('kernel.version') : null,
            );

            $directory = $this->directory($rootPath, $this->stringOption($input, 'path'), $package);
            $variables['directory'] = $this->relative($rootPath, $directory);
            $files = $maker->make($directory, $variables, (bool) $input->getOption('force'));
        } catch (PackageException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->title(sprintf('Package %s created in %s', $package, $variables['directory']));
        $this->renderFiles($output, $files);

        if (!$input->getOption('link')) {
            $output->definitionList([
                'Name' => $variables['alias'],
                'Namespace' => $variables['namespace'],
                'Manager' => $variables['namespace'] . '\\' . $variables['class'] . 'Manager',
            ]);
            $output->success([
                sprintf('%s is ready in %s.', $package, $variables['directory']),
                sprintf('To install it in this project, declare %s as a Composer path repository, then run: php bin/neo neophp:package:install %s:*@dev (--link does both when creating a package).', $variables['directory'], $package),
            ]);

            return self::SUCCESS;
        }

        try {
            if ($this->packages->addPathRepository($directory)) {
                $this->renderFiles($output, ['composer.json' => PackageManagerInterface::STATUS_UPDATED]);
            }

            $result = $this->packages->install($package . ':*@dev', false, (bool) $input->getOption('force'));
        } catch (PackageException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->renderFiles($output, $result['files']);
        $output->success(sprintf('%s is installed. Try it: php bin/neo %s:hello', $package, $variables['alias']));

        return self::SUCCESS;
    }

    protected function directory(string $rootPath, ?string $path, string $package): string
    {
        if ($path === null) {
            return $rootPath . DIRECTORY_SEPARATOR . self::DIRECTORY . DIRECTORY_SEPARATOR . substr($package, (int) strpos($package, '/') + 1);
        }

        $path = rtrim($path, '/\\');

        return preg_match('#^([a-zA-Z]:)?[/\\\\]#', $path) === 1 ? $path : $rootPath . DIRECTORY_SEPARATOR . $path;
    }

    protected function relative(string $rootPath, string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $rootPath), '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    protected function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}