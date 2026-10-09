<?php

declare(strict_types=1);

namespace NeoPHP\Package\Scheduler\Helper\Console;

use NeoPHP\Package\Scheduler\Runner\TaskRunner;
use NeoPHP\Package\Scheduler\SchedulerManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'schedule:test', description: 'Runs a scheduled task now, whatever its schedule')]
class ScheduleTestCommand extends AbstractConsole
{
    public function __construct(protected SchedulerManagerInterface $scheduler, protected TaskRunner $runner)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('name', InputArgument::OPTIONAL, 'The task name (see schedule:list)');
        $this->addExample('schedule:test');
        $this->addExample('schedule:test "app:report"');
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        if ($input->isArgumentProvided('name')) {
            return;
        }

        try {
            $names = array_map('strval', array_keys($this->scheduler->getTasks()));
        } catch (Throwable) {
            return;
        }

        if ($names !== []) {
            $input->setArgument('name', (string) $output->choice('Which task do you want to run?', $names));
        }
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) ($input->getArgument('name') ?? '');

        if ($name === '') {
            $output->error('Give the name of the task to run (see php bin/neo schedule:list).');

            return self::INVALID;
        }

        try {
            $task = $this->scheduler->find($name);
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->title(sprintf('Running "%s"', $name));
        $result = $this->runner->run($task);

        if (trim((string) $result['output']) !== '') {
            $output->writeln(rtrim((string) $result['output']));
            $output->newLine();
        }

        match ($result['status']) {
            TaskRunner::STATUS_SUCCESS => $output->success(sprintf('Task "%s" done in %s ms.', $name, number_format((float) $result['duration'], 2))),
            TaskRunner::STATUS_SKIPPED => $output->warning((string) $result['error']),
            default => $output->error(sprintf('Task "%s" failed: %s', $name, (string) $result['error'])),
        };

        return $result['status'] === TaskRunner::STATUS_FAILED ? self::FAILURE : self::SUCCESS;
    }
}