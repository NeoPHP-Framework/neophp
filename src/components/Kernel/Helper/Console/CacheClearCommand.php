<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Helper\Console;

use FilesystemIterator;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * @internal
 */
#[AsCommand(name: 'cache:clear', description: 'Clears the application cache (var/cache/): routes, Twig templates...', aliases: ['cc'])]
class CacheClearCommand extends AbstractConsole
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $this->addExample('cache:clear');
        $this->addExample('cache:clear --env=prod');
        $this->addExample('cache:clear -v');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $cachePath = (string) $this->container->get('kernel.cache_path');

        if (!is_dir($cachePath)) {
            $output->note(sprintf('The cache directory %s does not exist.', $cachePath));

            return self::SUCCESS;
        }

        $removed = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cachePath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getFilename() === '.gitkeep') {
                continue;
            }

            if ($file->isDir()) {
                @rmdir($file->getPathname());
                continue;
            }

            if (@unlink($file->getPathname())) {
                $removed++;
                $output->writeln('  <muted>removed</muted> ' . $file->getPathname(), OutputInterface::VERBOSITY_VERBOSE);
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        $output->success(sprintf('Cache cleared: %d file(s) removed from %s', $removed, $cachePath));

        return self::SUCCESS;
    }
}