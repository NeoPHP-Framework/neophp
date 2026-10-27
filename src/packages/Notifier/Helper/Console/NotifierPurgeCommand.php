<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Helper\Console;

use NeoPHP\Package\Notifier\NotifierManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputOption;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'notifier:purge', description: 'Deletes the old notifications of the database channel')]
class NotifierPurgeCommand extends AbstractConsole
{
    public function __construct(protected NotifierManagerInterface $notifier)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('days', null, InputOption::VALUE_REQUIRED, 'Age in days of the deleted notifications', '90');
        $input->addOption('read-only', null, InputOption::VALUE_NONE, 'Keeps the unread notifications');
        $this->setHelp('Schedule it with the Scheduler package to keep the table small.');
        $this->addExample('notifier:purge');
        $this->addExample('notifier:purge --days=30 --read-only');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $days = max(0, (int) $input->getOption('days'));

        try {
            $deleted = $this->notifier->purge($days, (bool) $input->getOption('read-only'));
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->success(sprintf('%d notification(s) older than %d day(s) deleted.', $deleted, $days));

        return self::SUCCESS;
    }
}