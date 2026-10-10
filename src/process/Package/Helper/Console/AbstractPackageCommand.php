<?php

declare(strict_types=1);

namespace NeoPHP\Process\Package\Helper\Console;

use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Package\PackageManagerInterface;

/**
 * @internal
 */
abstract class AbstractPackageCommand extends AbstractConsole
{
    public function __construct(protected PackageManagerInterface $packages)
    {
    }

    protected function renderFiles(OutputInterface $output, array $files): void
    {
        foreach ($files as $path => $status) {
            $style = match ($status) {
                PackageManagerInterface::STATUS_CREATED, PackageManagerInterface::STATUS_UPDATED => 'success',
                PackageManagerInterface::STATUS_OVERWRITTEN, PackageManagerInterface::STATUS_CHANGED, PackageManagerInterface::STATUS_REMOVED => 'comment',
                default => 'muted',
            };

            $output->writeln(sprintf('  <%1$s>%2$s</%1$s>  %3$s', $style, str_pad((string) $status, 11), $path), $status === PackageManagerInterface::STATUS_SKIPPED ? OutputInterface::VERBOSITY_VERBOSE : OutputInterface::VERBOSITY_NORMAL);
        }

        if (in_array(PackageManagerInterface::STATUS_CHANGED, $files, true)) {
            $output->warning('Some configuration files were changed in the project: the new version of the package is in the .dist file next to them. Merge it, then delete the .dist file.');
        }
    }
}