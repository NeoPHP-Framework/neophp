<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Helper\Console;

use NeoPHP\Package\Queue\Contract\QueueInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputOption;
use Throwable;

#[AsCommand(name: 'queue:flush-failed', description: 'Deletes all the failed messages')]
class QueueFlushFailedCommand extends AbstractConsole
{
    public function __construct(protected QueueInterface $queue)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('transport', null, InputOption::VALUE_REQUIRED, 'Only this transport');
        $this->addExample('queue:flush-failed');
        $this->addExample('queue:flush-failed --transport=async -f');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        if (!(bool) $input->getOption('force') && $input->isInteractive() && !$output->confirm('Delete all the failed messages?', false)) {
            $output->note('Nothing deleted.');

            return self::SUCCESS;
        }

        $names = $input->getOption('transport') !== null ? [(string) $input->getOption('transport')] : $this->queue->getTransportNames();
        $count = 0;

        try {
            foreach ($names as $name) {
                $count += $this->queue->transport($name)->flushFailed();
            }
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->success(sprintf('%d failed message(s) deleted.', $count));

        return self::SUCCESS;
    }
}