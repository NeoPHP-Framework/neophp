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

#[AsCommand(name: 'queue:failed', description: 'Lists the failed messages')]
class QueueFailedCommand extends AbstractConsole
{
    public function __construct(protected QueueInterface $queue)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('transport', null, InputOption::VALUE_REQUIRED, 'Only this transport');
        $input->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of messages per transport', 50);
        $this->addExample('queue:failed');
        $this->addExample('queue:failed --transport=async --limit=10');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $names = $input->getOption('transport') !== null ? [(string) $input->getOption('transport')] : $this->queue->getTransportNames();
        $rows = [];

        foreach ($names as $name) {
            try {
                foreach ($this->queue->transport($name)->getFailed((int) $input->getOption('limit')) as $envelope) {
                    $rows[] = [
                        (string) $envelope->getId(),
                        $name,
                        $envelope->getQueue(),
                        $envelope->getMessageClass(),
                        $envelope->getAttempts(),
                        $envelope->getFailedAt() !== null ? date('Y-m-d H:i:s', $envelope->getFailedAt()) : '',
                        mb_substr((string) $envelope->getLastError(), 0, 120),
                    ];
                }
            } catch (Throwable $exception) {
                $output->warning(sprintf('%s: %s', $name, $exception->getMessage()));
            }
        }

        $output->title('Failed messages');

        if ($rows === []) {
            $output->success('No failed message.');

            return self::SUCCESS;
        }

        $output->table(['Id', 'Transport', 'Queue', 'Message', 'Attempts', 'Failed at', 'Error'], $rows);
        $output->text('Retry with <info>php bin/neo queue:retry <id|all></info>, delete with <info>queue:forget <id></info> or <info>queue:flush-failed</info>.');

        return self::SUCCESS;
    }
}