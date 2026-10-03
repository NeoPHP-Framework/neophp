<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Helper\Console;

use NeoPHP\Package\Queue\Contract\QueueInterface;
use NeoPHP\Package\Queue\Transport\SyncTransport;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;
use Throwable;

#[AsCommand(name: 'queue:status', description: 'Shows the number of ready, delayed, reserved and failed messages')]
class QueueStatusCommand extends AbstractConsole
{
    public function __construct(protected QueueInterface $queue)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('transport', InputArgument::OPTIONAL, 'Only this transport');
        $this->addExample('queue:status');
        $this->addExample('queue:status async');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $names = $input->getArgument('transport') !== null ? [(string) $input->getArgument('transport')] : $this->queue->getTransportNames();
        $rows = [];
        $code = self::SUCCESS;

        foreach ($names as $name) {
            try {
                $transport = $this->queue->transport($name);

                if ($transport instanceof SyncTransport) {
                    $rows[] = [$name, '<muted>sync</muted>', '-', '-', '-', '-'];
                    continue;
                }

                $queues = $transport->getQueues();

                foreach ($queues === [] ? [$transport->getDefaultQueue()] : $queues as $queue) {
                    $count = $transport->count((string) $queue);
                    $rows[] = [$name, (string) $queue, $count['ready'], $count['delayed'], $count['reserved'], $count['failed'] > 0 ? '<error>' . $count['failed'] . '</error>' : '0'];
                }
            } catch (Throwable $exception) {
                $rows[] = [$name, '<error>' . $exception->getMessage() . '</error>', '', '', '', ''];
                $code = self::FAILURE;
            }
        }

        $output->title('Queue status');
        $output->table(['Transport', 'Queue', 'Ready', 'Delayed', 'Reserved', 'Failed'], $rows);
        $output->text(sprintf('Default transport: <info>%s</info>', $this->queue->getDefaultTransportName()));

        return $code;
    }
}