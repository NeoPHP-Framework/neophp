<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cache\Helper\Console;

use NeoPHP\Component\Cache\Contract\CacheManagerInterface;
use NeoPHP\Component\Cache\Exception\CacheException;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;

#[AsCommand(name: 'cache:pool:delete', description: 'Deletes an item from a cache pool')]
class CachePoolDeleteCommand extends AbstractConsole
{
    public function __construct(protected CacheManagerInterface $cache)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('pool', InputArgument::REQUIRED, 'The pool name', null, 'Pool name');
        $input->addArgument('key', InputArgument::REQUIRED, 'The key of the item', null, 'Item key');
        $this->addExample('cache:pool:delete app weather.paris');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $pool = (string) $input->getArgument('pool');
        $key = (string) $input->getArgument('key');

        try {
            $cache = $this->cache->pool($pool);

            if (!$cache->has($key)) {
                $output->note(sprintf('The key "%s" is not in the pool "%s".', $key, $pool));

                return self::SUCCESS;
            }

            $cache->delete($key);
        } catch (CacheException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->success(sprintf('The key "%s" was deleted from the pool "%s".', $key, $pool));

        return self::SUCCESS;
    }
}