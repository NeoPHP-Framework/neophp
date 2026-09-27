<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cache\Helper\Console;

use NeoPHP\Component\Cache\Contract\CacheManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;

#[AsCommand(name: 'cache:pool:list', description: 'Lists the cache pools configured in config/framework/cache.yaml')]
class CachePoolListCommand extends AbstractConsole
{
    public function __construct(protected CacheManagerInterface $cache)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $this->addExample('cache:pool:list');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];

        foreach ($this->cache->getPoolNames() as $name) {
            $config = $this->cache->getPoolConfig($name);
            $rows[] = [
                $name === $this->cache->getDefaultPool() ? $name . ' <muted>(default)</muted>' : $name,
                (string) $config['adapter'],
                $config['default_ttl'] === null ? 'forever' : $config['default_ttl'] . ' s',
                (string) $config['namespace'],
            ];
        }

        $output->table(['Pool', 'Adapter', 'Default TTL', 'Namespace'], $rows);

        return self::SUCCESS;
    }
}