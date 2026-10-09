<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Helper\Console;

use NeoPHP\Package\Queue\QueueManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\IO\InputOption;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'queue:purge', description: 'Deletes the pending messages of a transport (failed messages are kept)')]
class QueuePurgeCommand extends AbstractConsole
{
    public function __construct(protected QueueManagerInterface $queue)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('transport', InputArgument::OPTIONAL, 'The transport (default: the default transport)');
        $input->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Only this queue');
        $this->addExample('queue:purge');
        $this->addExample('queue:purge async --queue=emails -f');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) ($input->getArgument('transport') ?? $this->queue->getDefaultTransportName());
        $queue = $input->getOption('queue') !== null ? (string) $input->getOption('queue') : null;

        if (!(bool) $input->getOption('force') && $input->isInteractive() && !$output->confirm(sprintf('Delete the pending messages of "%s"%s?', $name, $queue !== null ? ' (queue ' . $queue . ')' : ''), false)) {
            $output->note('Nothing deleted.');

            return self::SUCCESS;
        }

        try {
            $count = $this->queue->transport($name)->purge($queue);
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->success(sprintf('%d message(s) deleted from "%s".', $count, $name));

        return self::SUCCESS;
    }
}