<?php

declare(strict_types=1);

namespace NeoPHP\Package\Scheduler\Helper\Console;

use NeoPHP\Package\Scheduler\History\HistoryStore;
use NeoPHP\Package\Scheduler\SchedulerManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'schedule:list', description: 'Lists the scheduled tasks with their next and last run')]
class ScheduleListCommand extends AbstractConsole
{
    public const HEARTBEAT_DELAY = 120;

    public function __construct(protected SchedulerManagerInterface $scheduler, protected HistoryStore $history)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $this->addExample('schedule:list');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        try {
            $tasks = $this->scheduler->getTasks();
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->title('Scheduled tasks');

        if ($tasks === []) {
            $output->note('No scheduled task: add #[AsScheduledTask] on a class, a "tasks" entry in config/packages/scheduler.yaml or a ScheduleProviderInterface.');

            return self::SUCCESS;
        }

        $last = $this->history->lastRuns();
        $rows = [];

        foreach ($tasks as $name => $task) {
            $run = $last[$name] ?? null;
            $status = $run === null ? '<muted>never</muted>' : match ((string) ($run['status'] ?? '')) {
                'success' => '<success>success</success>',
                'skipped' => '<comment>skipped</comment>',
                default => '<error>failed</error>',
            };

            try {
                $next = $task->getCron()->getNextRunDate()->format('Y-m-d H:i T');
            } catch (Throwable $exception) {
                $next = '<error>' . $exception->getMessage() . '</error>';
            }

            $rows[] = [
                $name,
                $task->getType(),
                $task->getExpression(),
                $next,
                $run !== null ? date('Y-m-d H:i:s', (int) ($run['timestamp'] ?? 0)) . ' (' . number_format((float) ($run['duration'] ?? 0), 0) . ' ms)' : '',
                $status,
                $task->getSource() . ($task->isWithoutOverlapping() ? ', no overlap' : ''),
            ];
        }

        $output->table(['Task', 'Type', 'Cron', 'Next run', 'Last run', 'Status', 'Source'], $rows);
        $beat = $this->history->getLastBeat();

        if ($beat === null || time() - $beat > self::HEARTBEAT_DELAY) {
            $output->warning('schedule:run did not run in the last 2 minutes: configure the system cron (* * * * * php bin/neo schedule:run) or run php bin/neo schedule:work.');
        } else {
            $output->text(sprintf('schedule:run last ran at %s.', date('H:i:s', $beat)));
        }

        return self::SUCCESS;
    }
}