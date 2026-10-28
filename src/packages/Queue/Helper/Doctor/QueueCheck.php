<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Helper\Doctor;

use NeoPHP\Package\Queue\QueueManagerInterface;
use NeoPHP\Package\Queue\Transport\SyncTransport;
use NeoPHP\Process\Doctor\Contract\CheckInterface;
use NeoPHP\Process\Doctor\Model\CheckResult;
use Throwable;

/**
 * @internal
 */
class QueueCheck implements CheckInterface
{
    public function __construct(protected QueueManagerInterface $queue)
    {
    }

    public function getName(): string
    {
        return 'Queue';
    }

    public function run(): iterable
    {
        foreach ($this->queue->getTransportNames() as $name) {
            $label = 'Transport ' . $name;

            try {
                $transport = $this->queue->transport($name);

                if ($transport instanceof SyncTransport) {
                    continue;
                }

                $counts = $transport->count();
            } catch (Throwable $exception) {
                yield CheckResult::error($label, $exception->getMessage(), 'Check QUEUE_DSN and the server of the transport (database, Redis, directory).');

                continue;
            }

            $waiting = (int) ($counts['ready'] ?? 0) + (int) ($counts['delayed'] ?? 0);
            $failed = (int) ($counts['failed'] ?? 0);

            yield CheckResult::ok($label, sprintf('%d waiting, %d in progress', $waiting, (int) ($counts['reserved'] ?? 0)));

            if ($failed > 0) {
                yield CheckResult::warning('Failed messages', sprintf('%d on %s', $failed, $name), sprintf('Inspect them with php bin/neo queue:failed --transport=%s, then queue:retry or queue:forget.', $name));
            }
        }
    }
}