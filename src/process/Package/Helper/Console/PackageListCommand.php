<?php

declare(strict_types=1);

namespace NeoPHP\Process\Package\Helper\Console;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Kernel\KernelManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Package\PackageManagerInterface;

/**
 * @internal
 */
#[AsCommand(name: 'neophp:package:list', description: 'Lists the installed NeoPHP packages')]
class PackageListCommand extends AbstractPackageCommand
{
    public function __construct(PackageManagerInterface $packages, protected ContainerManagerInterface $container)
    {
        parent::__construct($packages);
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $this->addExample('neophp:package:list');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $packages = $this->packages->all();

        if ($packages === []) {
            $output->note('No NeoPHP package is installed. Install one with: php bin/neo neophp:package:install vendor/name');

            return self::SUCCESS;
        }

        $kernel = $this->container->bound(KernelManagerInterface::class) ? $this->container->get(KernelManagerInterface::class) : null;
        $rows = [];

        foreach ($packages as $package) {
            $rows[] = [
                $package['name'],
                $package['alias'],
                $package['version'],
                $this->status($package, $kernel),
                $this->configuration($package),
            ];
        }

        $output->table(['Package', 'Name', 'Version', 'Status', 'Configuration'], $rows);

        return self::SUCCESS;
    }

    protected function status(array $package, ?KernelManagerInterface $kernel): string
    {
        if ($package['modules'] === []) {
            return '<muted>no module</muted>';
        }

        if ($kernel === null) {
            return count($package['modules']) . ' module(s)';
        }

        $enabled = count(array_filter($package['modules'], static fn (string $module): bool => isset($kernel->getModules()[$module])));

        return match ($enabled) {
            0 => '<comment>disabled</comment>',
            count($package['modules']) => '<success>enabled</success>',
            default => sprintf('<comment>%d/%d enabled</comment>', $enabled, count($package['modules'])),
        };
    }

    protected function configuration(array $package): string
    {
        if ($package['config'] === null) {
            return '<muted>-</muted>';
        }

        return is_dir($this->packages->getConfigDirectory($package['alias']))
            ? 'config/packages/' . $package['alias'] . '/'
            : '<comment>missing (run neophp:package:update)</comment>';
    }
}