<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Helper\Console;

use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Package\Queue\Contract\QueueInterface;
use NeoPHP\Package\Queue\Envelope;
use NeoPHP\Package\Queue\Transport\SyncTransport;
use NeoPHP\Package\Queue\Worker\Worker;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\IO\InputOption;
use Throwable;

#[AsCommand(name: 'queue:work', description: 'Consumes the messages of a queue transport')]
class QueueWorkCommand extends AbstractConsole
{
    public function __construct(protected QueueInterface $queue, protected ContainerInterface $container)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('transport', InputArgument::OPTIONAL, 'The transport to consume (default: the default transport)');
        $input->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Comma separated queues, by priority (default: the transport queue)');
        $input->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Stop after N messages', 0);
        $input->addOption('time-limit', 't', InputOption::VALUE_REQUIRED, 'Stop after N seconds', 0);
        $input->addOption('memory-limit', 'm', InputOption::VALUE_REQUIRED, 'Stop when the memory exceeds N MB', 128);
        $input->addOption('sleep', 's', InputOption::VALUE_REQUIRED, 'Seconds to wait when the queue is empty', 1);
        $input->addOption('stop-when-empty', null, InputOption::VALUE_NONE, 'Stop when the queue is empty');
        $input->addOption('once', null, InputOption::VALUE_NONE, 'Handle a single message then stop');
        $this->addExample('queue:work');
        $this->addExample('queue:work async --queue=high,default --limit=100 --time-limit=3600');
        $this->addExample('queue:work --stop-when-empty');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) ($input->getArgument('transport') ?? $this->queue->getDefaultTransportName());

        try {
            $transport = $this->queue->transport($name);
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($transport instanceof SyncTransport) {
            $output->error(sprintf('The transport "%s" is synchronous: its messages are handled when dispatched, there is nothing to consume.', $name));

            return self::FAILURE;
        }

        $queues = array_values(array_filter(array_map('trim', explode(',', (string) ($input->getOption('queue') ?? '')))));
        $output->title('Queue worker');
        $output->definitionList([
            'Transport' => $name,
            'Queues' => implode(', ', $queues === [] ? [$transport->getDefaultQueue()] : $queues),
            'Stop' => 'Ctrl+C, SIGTERM or php bin/neo queue:restart',
        ]);

        $worker = $this->container->get(Worker::class);
        $result = $worker->run($transport, [
            'queues' => $queues,
            'limit' => (int) $input->getOption('limit'),
            'time_limit' => (int) $input->getOption('time-limit'),
            'memory_limit' => (int) $input->getOption('memory-limit'),
            'sleep' => (float) $input->getOption('sleep'),
            'stop_when_empty' => (bool) $input->getOption('stop-when-empty'),
            'once' => (bool) $input->getOption('once'),
        ], static function (string $status, Envelope $envelope, float $duration, ?Throwable $error, int $delay) use ($output): void {
            $label = match ($status) {
                Worker::STATUS_HANDLED => '<success>handled</success>',
                Worker::STATUS_RETRY => sprintf('<comment>retry in %ds</comment>', $delay),
                default => '<error>failed</error>',
            };
            $output->writeln(sprintf(
                '  %s  <info>%s</info> <muted>#%s %s attempt %d/%d</muted>  %s  %s ms',
                date('H:i:s'),
                $envelope->getMessageClass(),
                (string) $envelope->getId(),
                $envelope->getQueue(),
                $envelope->getAttempts(),
                $envelope->getMaxAttempts(),
                $label,
                number_format($duration, 2),
            ));

            if ($error !== null) {
                $output->writeln(sprintf('           <muted>%s: %s</muted>', $error::class, $error->getMessage()));
            }
        });

        $output->newLine();
        $output->text(sprintf('Worker stopped (%s): %d handled, %d retried, %d failed in %ss.', $result['reason'], $result['handled'], $result['retried'], $result['failed'], $result['duration']));

        return self::SUCCESS;
    }
}