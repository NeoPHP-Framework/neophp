<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Helper\Console;

use NeoPHP\Package\Queue\QueueManagerInterface;
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
#[AsCommand(name: 'queue:forget', description: 'Deletes a failed message')]
class QueueForgetCommand extends AbstractConsole
{
    public function __construct(protected QueueManagerInterface $queue)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('id', InputArgument::REQUIRED, 'The failed message id', null, 'Id of the failed message');
        $input->addOption('transport', null, InputOption::VALUE_REQUIRED, 'Only this transport');
        $this->addExample('queue:forget 12');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('id');
        $names = $input->getOption('transport') !== null ? [(string) $input->getOption('transport')] : $this->queue->getTransportNames();

        try {
            foreach ($names as $name) {
                if ($this->queue->transport($name)->forgetFailed($id)) {
                    $output->success(sprintf('Failed message %s deleted from "%s".', $id, $name));

                    return self::SUCCESS;
                }
            }
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->error(sprintf('Failed message %s not found.', $id));

        return self::FAILURE;
    }
}