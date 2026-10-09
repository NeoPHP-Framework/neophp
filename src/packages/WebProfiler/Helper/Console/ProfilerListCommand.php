<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Helper\Console;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Package\WebProfiler\Contract\ProfileStorageInterface;
use NeoPHP\Package\WebProfiler\Util\ValueExporter;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputOption;

/**
 * @internal
 */
#[AsCommand(name: 'profiler:list', description: 'Lists the last profiles collected by the web profiler')]
class ProfilerListCommand extends AbstractConsole
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of profiles', '20');
        $input->addOption('url', null, InputOption::VALUE_REQUIRED, 'Filter by URL (contains)');
        $input->addOption('method', null, InputOption::VALUE_REQUIRED, 'Filter by HTTP method');
        $input->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status code (404) or class (5xx)');
        $input->addOption('ip', null, InputOption::VALUE_REQUIRED, 'Filter by client IP');
        $this->addExample('profiler:list --status=5xx --limit=10');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->container->bound(ProfileStorageInterface::class)) {
            $output->error('The WebProfiler package is not registered in the kernel.');

            return self::FAILURE;
        }

        $filters = [];

        foreach (['url', 'method', 'status', 'ip'] as $name) {
            $filters[$name] = (string) ($input->getOption($name) ?? '');
        }

        $profiles = $this->container->get(ProfileStorageInterface::class)->find(max(1, (int) $input->getOption('limit')), $filters);

        if ($profiles === []) {
            $output->info('No profile found.');

            return self::SUCCESS;
        }

        $output->table(['Token', 'Date', 'Method', 'Status', 'URL', 'Duration', 'Memory', 'IP'], array_map(static fn (array $profile): array => [
            (string) $profile['token'],
            date('Y-m-d H:i:s', (int) ($profile['time'] ?? 0)),
            (string) ($profile['method'] ?? ''),
            (string) ($profile['status'] ?? ''),
            (string) ($profile['url'] ?? ''),
            ValueExporter::formatDuration((float) ($profile['duration'] ?? 0)),
            ValueExporter::formatBytes((int) ($profile['memory'] ?? 0)),
            (string) ($profile['ip'] ?? ''),
        ], $profiles));

        return self::SUCCESS;
    }
}