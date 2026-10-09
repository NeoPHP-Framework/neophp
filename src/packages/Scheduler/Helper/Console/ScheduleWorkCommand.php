<?php

declare(strict_types=1);

namespace NeoPHP\Package\Scheduler\Helper\Console;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Package\Scheduler\Provider\SchedulerProvider;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputOption;

/**
 * @internal
 */
#[AsCommand(name: 'schedule:work', description: 'Runs schedule:run every minute in the foreground (development, Windows)')]
class ScheduleWorkCommand extends AbstractConsole
{
    protected bool $stop = false;

    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('runs', null, InputOption::VALUE_REQUIRED, 'Stop after N minutes (0: never)', 0);
        $this->addExample('schedule:work');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $config = (array) $this->container->get(SchedulerProvider::CONFIG_ID);
        $root = (string) $config['root_path'];
        $console = (string) $config['console'];
        $console = preg_match('#^([A-Za-z]:)?[/\\\\]#', $console) === 1 ? $console : $root . DIRECTORY_SEPARATOR . $console;
        $binary = (string) ($config['php_binary'] ?? PHP_BINARY);
        $runs = (int) $input->getOption('runs');
        $this->registerSignals();

        $output->title('Scheduler worker');
        $output->text('Runs <info>schedule:run</info> at the start of every minute. Press Ctrl+C to stop.');

        for ($run = 0; !$this->stop && ($runs === 0 || $run < $runs); $run++) {
            $this->waitNextMinute($run === 0);

            if ($this->stop) {
                break;
            }

            $errors = (string) tempnam(sys_get_temp_dir(), 'neo_schedule_');
            $process = proc_open([$binary, $console, 'schedule:run', '--no-interaction', ...($output->isDecorated() ? ['--ansi'] : ['--no-ansi'])], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']], $pipes, $root);

            if (!is_resource($process)) {
                @unlink($errors);
                $output->error('Unable to start schedule:run.');

                return self::FAILURE;
            }

            fclose($pipes[0]);
            $result = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $code = proc_close($process);
            $result = trim($result . (string) @file_get_contents($errors));
            @unlink($errors);
            $output->writeln(sprintf('<muted>%s schedule:run (exit %d)</muted>', date('Y-m-d H:i:s'), $code));

            if ($result !== '') {
                $output->writeln($result);
            }
        }

        return self::SUCCESS;
    }

    protected function waitNextMinute(bool $first): void
    {
        if ($first && (int) date('s') < 2) {
            return;
        }

        $target = (intdiv(time(), 60) + 1) * 60;

        while (!$this->stop && time() < $target) {
            usleep(250000);

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    protected function registerSignals(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->stop = true;
            });
        }
    }
}