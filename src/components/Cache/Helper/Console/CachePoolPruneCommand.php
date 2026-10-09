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

/**
 * @internal
 */
#[AsCommand(name: 'cache:pool:prune', description: 'Removes the expired items of the cache pools')]
class CachePoolPruneCommand extends AbstractConsole
{
    public function __construct(protected CacheManagerInterface $cache)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('pools', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'The pools to prune (all pools by default)', []);
        $this->setHelp('Useful for the filesystem and database adapters (APCu and array expire by themselves). Schedule it with cron.');
        $this->addExample('cache:pool:prune');
        $this->addExample('cache:pool:prune app');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $pools = array_map('strval', (array) $input->getArgument('pools')) ?: $this->cache->getPoolNames();
        $rows = [];

        try {
            foreach ($pools as $name) {
                $rows[] = [$name, (string) $this->cache->pool($name)->prune()];
            }
        } catch (CacheException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->table(['Pool', 'Pruned items'], $rows);

        return self::SUCCESS;
    }
}