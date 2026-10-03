<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Helper\Console;

use NeoPHP\Package\Queue\Contract\QueueInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\IO\InputOption;
use Throwable;

#[AsCommand(name: 'queue:retry', description: 'Sends failed messages back to their queue')]
class QueueRetryCommand extends AbstractConsole
{
    public function __construct(protected QueueInterface $queue)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('id', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Failed message ids, or "all"', null, 'Id of the failed message (or "all")');
        $input->addOption('transport', null, InputOption::VALUE_REQUIRED, 'Only this transport');
        $this->addExample('queue:retry 12');
        $this->addExample('queue:retry all --transport=async');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $ids = array_map('strval', (array) $input->getArgument('id'));
        $names = $input->getOption('transport') !== null ? [(string) $input->getOption('transport')] : $this->queue->getTransportNames();
        $retried = 0;
        $missing = [];

        try {
            if (in_array('all', $ids, true)) {
                foreach ($names as $name) {
                    $transport = $this->queue->transport($name);

                    do {
                        $failed = $transport->getFailed(500);

                        foreach ($failed as $envelope) {
                            $retried += $transport->retryFailed((string) $envelope->getId()) ? 1 : 0;
                        }
                    } while (count($failed) === 500);
                }
            } else {
                foreach ($ids as $id) {
                    $found = false;

                    foreach ($names as $name) {
                        if ($this->queue->transport($name)->retryFailed($id)) {
                            $found = true;
                            $retried++;
                            break;
                        }
                    }

                    if (!$found) {
                        $missing[] = $id;
                    }
                }
            }
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($missing !== []) {
            $output->warning(sprintf('Failed message(s) not found: %s', implode(', ', $missing)));
        }

        $output->success(sprintf('%d message(s) sent back to their queue.', $retried));

        return $missing === [] ? self::SUCCESS : self::FAILURE;
    }
}