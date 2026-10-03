<?php

declare(strict_types=1);

namespace NeoPHP\Package\Scheduler\Helper\Console;

use DateTimeImmutable;
use NeoPHP\Package\Scheduler\Runner\TaskRunner;
use NeoPHP\Package\Scheduler\Scheduler;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use Throwable;

#[AsCommand(name: 'schedule:run', description: 'Runs the scheduled tasks that are due (call it every minute from the system cron)')]
class ScheduleRunCommand extends AbstractConsole
{
    public function __construct(protected Scheduler $scheduler, protected TaskRunner $runner)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $this->setHelp('Add this line to the crontab of the server (crontab -e): * * * * * cd /path/to/project && php bin/neo schedule:run >> /dev/null 2>&1. On Windows, use the Task Scheduler or run php bin/neo schedule:work.');
        $this->addExample('schedule:run');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $now = new DateTimeImmutable();
        $this->runner->getHistory()->beat($now->getTimestamp());

        try {
            $tasks = $this->scheduler->getDueTasks($now);
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($tasks === []) {
            $output->writeln(sprintf('  %s  <muted>No scheduled task is due.</muted>', $now->format('H:i')), OutputInterface::VERBOSITY_VERBOSE);

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($tasks as $name => $task) {
            $output->writeln(sprintf('  %s  <info>%s</info> <muted>(%s)</muted>', $now->format('H:i'), $name, $task->getTargetLabel()));
            $result = $this->runner->run($task);
            $failed += $result['status'] === TaskRunner::STATUS_FAILED ? 1 : 0;
            $label = match ($result['status']) {
                TaskRunner::STATUS_SUCCESS => '<success>success</success>',
                TaskRunner::STATUS_SKIPPED => '<comment>skipped</comment>',
                default => '<error>failed</error>',
            };
            $output->writeln(sprintf('         %s  %s ms%s', $label, number_format((float) $result['duration'], 2), $result['error'] !== null ? '  <muted>' . $result['error'] . '</muted>' : ''));

            if ($output->isVerbose() && trim((string) $result['output']) !== '') {
                $output->writeln(trim((string) $result['output']));
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}