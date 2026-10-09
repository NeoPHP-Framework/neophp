<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cache\Helper\Console;

use NeoPHP\Component\Cache\CacheManagerInterface;
use NeoPHP\Component\Cache\Exception\CacheException;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\IO\InputOption;

/**
 * @internal
 */
#[AsCommand(name: 'cache:pool:clear', description: 'Clears one or more cache pools')]
class CachePoolClearCommand extends AbstractConsole
{
    public function __construct(protected CacheManagerInterface $cache)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('pools', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'The pools to clear', []);
        $input->addOption('all', 'a', InputOption::VALUE_NONE, 'Clear every configured pool');
        $this->setHelp('Unlike cache:clear (which empties var/cache/), this command clears the cache pools whatever their adapter (filesystem, apcu, database, array).');
        $this->addExample('cache:pool:clear app');
        $this->addExample('cache:pool:clear --all');
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        if ($input->getOption('all') || (array) $input->getArgument('pools') !== []) {
            return;
        }

        $names = $this->cache->getPoolNames();
        $input->setArgument('pools', [(string) $output->select('Which pool do you want to clear?', array_combine($names, $names), $this->cache->getDefaultPool())]);
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $pools = $input->getOption('all') ? $this->cache->getPoolNames() : array_map('strval', (array) $input->getArgument('pools'));

        if ($pools === []) {
            $output->error('Give at least one pool name, or use --all.');

            return self::FAILURE;
        }

        $failures = 0;

        foreach ($pools as $name) {
            try {
                $cleared = $this->cache->pool($name)->clear();
            } catch (CacheException $exception) {
                $output->error($exception->getMessage());
                $failures++;
                continue;
            }

            if ($cleared) {
                $output->writeln(sprintf('  <info>cleared</info> %s', $name));
            } else {
                $output->writeln(sprintf('  <error>failed</error>  %s', $name));
                $failures++;
            }
        }

        if ($failures > 0) {
            return self::FAILURE;
        }

        $output->success(sprintf('%d cache pool(s) cleared.', count($pools)));

        return self::SUCCESS;
    }
}