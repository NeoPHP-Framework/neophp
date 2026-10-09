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
#[AsCommand(name: 'cache:pool:invalidate-tags', description: 'Invalidates the cache items tagged with the given tags')]
class CachePoolInvalidateTagsCommand extends AbstractConsole
{
    public function __construct(protected CacheManagerInterface $cache)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('tags', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'The tags to invalidate', null, 'Tags to invalidate (space separated)');
        $input->addOption('pool', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these pools (all pools by default)', []);
        $this->addExample('cache:pool:invalidate-tags products');
        $this->addExample('cache:pool:invalidate-tags products categories --pool=app');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $tags = array_map('strval', (array) $input->getArgument('tags'));
        $pools = array_map('strval', (array) $input->getOption('pool')) ?: $this->cache->getPoolNames();

        try {
            foreach ($pools as $name) {
                $this->cache->pool($name)->invalidateTags($tags);
                $output->writeln(sprintf('  <info>invalidated</info> %s', $name), OutputInterface::VERBOSITY_VERBOSE);
            }
        } catch (CacheException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->success(sprintf('Tag(s) %s invalidated in %d pool(s).', implode(', ', $tags), count($pools)));

        return self::SUCCESS;
    }
}