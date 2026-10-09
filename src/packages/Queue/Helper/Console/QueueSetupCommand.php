<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Helper\Console;

use NeoPHP\Package\Queue\Contract\SetupableTransportInterface;
use NeoPHP\Package\Queue\QueueManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'queue:setup', description: 'Creates the tables or directories of the queue transports')]
class QueueSetupCommand extends AbstractConsole
{
    public function __construct(protected QueueManagerInterface $queue)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('transport', InputArgument::OPTIONAL, 'Only this transport');
        $this->addExample('queue:setup');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $names = $input->getArgument('transport') !== null ? [(string) $input->getArgument('transport')] : $this->queue->getTransportNames();
        $code = self::SUCCESS;

        foreach ($names as $name) {
            try {
                $transport = $this->queue->transport($name);

                if (!$transport instanceof SetupableTransportInterface) {
                    $output->writeln(sprintf('  <muted>skipped</muted>  %s (nothing to set up)', $name));
                    continue;
                }

                $transport->setup();
                $output->writeln(sprintf('  <success>ready</success>    %s', $name));
            } catch (Throwable $exception) {
                $output->writeln(sprintf('  <error>error</error>    %s: %s', $name, $exception->getMessage()));
                $code = self::FAILURE;
            }
        }

        if ($code === self::SUCCESS) {
            $output->success('The queue transports are ready.');
        }

        return $code;
    }
}