<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Helper\Console;

use NeoPHP\Package\Queue\Worker\RestartSignal;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use Throwable;

#[AsCommand(name: 'queue:restart', description: 'Asks the running workers to stop after their current message')]
class QueueRestartCommand extends AbstractConsole
{
    public function __construct(protected RestartSignal $signal)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $this->addExample('queue:restart');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->signal->send();
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->success('Restart signal sent: the workers stop after their current message (your process manager restarts them).');

        return self::SUCCESS;
    }
}