<?php

declare(strict_types=1);

namespace NeoPHP\Package\Scheduler\Helper\Console;

use DateTimeImmutable;
use NeoPHP\Package\Scheduler\Cron\CronExpression;
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
#[AsCommand(name: 'schedule:next', description: 'Explains a cron expression and shows its next run dates', aliases: ['cron:explain'])]
class ScheduleNextCommand extends AbstractConsole
{
    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('expression', InputArgument::REQUIRED, 'The cron expression (quoted)', null, 'Cron expression (e.g. */5 * * * *)');
        $input->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'Number of run dates', 5);
        $input->addOption('timezone', 'z', InputOption::VALUE_REQUIRED, 'Timezone (default: the PHP timezone)');
        $this->addExample('schedule:next "*/15 9-17 * * mon-fri"');
        $this->addExample('cron:explain @daily --count=3 --timezone=Europe/Paris');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        try {
            $cron = new CronExpression((string) $input->getArgument('expression'), $input->getOption('timezone') !== null ? (string) $input->getOption('timezone') : null);
            $dates = $cron->getNextRunDates(max(1, min(100, (int) $input->getOption('count'))));
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->title(sprintf('Cron expression "%s"', $cron->getSource()));
        $output->definitionList([
            'Expression' => $cron->getExpression(),
            'Timezone' => $cron->getTimezone()->getName(),
            'Minute' => $this->values($cron, 'minute', 60),
            'Hour' => $this->values($cron, 'hour', 24),
            'Day of month' => $this->values($cron, 'day', 31),
            'Month' => $this->values($cron, 'month', 12),
            'Day of week' => $this->values($cron, 'weekday', 7, ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']),
        ]);
        $output->section('Next run dates');
        $output->listing(array_map(static fn (DateTimeImmutable $date): string => $date->format('Y-m-d H:i (D) T'), $dates));

        return self::SUCCESS;
    }

    protected function values(CronExpression $cron, string $field, int $all, array $names = []): string
    {
        $values = $cron->getFieldValues($field);

        if (count($values) === $all) {
            return 'every';
        }

        return implode(', ', array_map(static fn (int $value): string => (string) ($names[$value] ?? $value), $values));
    }
}