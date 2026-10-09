<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Helper\Console;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Package\WebProfiler\Contract\ProfileStorageInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputOption;

/**
 * @internal
 */
#[AsCommand(name: 'profiler:clear', description: 'Deletes the stored profiles of the web profiler')]
class ProfilerClearCommand extends AbstractConsole
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('expired', null, InputOption::VALUE_NONE, 'Only delete the profiles over max_profiles or older than lifetime');
        $this->addExample('profiler:clear');
        $this->addExample('profiler:clear --expired');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->container->bound(ProfileStorageInterface::class)) {
            $output->error('The WebProfiler package is not registered in the kernel.');

            return self::FAILURE;
        }

        $storage = $this->container->get(ProfileStorageInterface::class);
        $count = $input->getOption('expired') ? $storage->purge() : $storage->clear();

        $output->success(sprintf('%d profile(s) deleted.', $count));

        return self::SUCCESS;
    }
}