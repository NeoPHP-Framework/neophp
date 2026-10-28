<?php

declare(strict_types=1);

namespace NeoPHP\Process\Doctor\Helper\Console;

use NeoPHP\Component\Container\Attribute\Autowire;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\Formatter;
use NeoPHP\Process\Console\IO\InputOption;
use NeoPHP\Process\Doctor\DoctorManagerInterface;
use NeoPHP\Process\Doctor\Exception\DoctorException;
use NeoPHP\Process\Doctor\Model\CheckResult;
use NeoPHP\Process\Doctor\Model\DoctorReport;

/**
 * @internal
 */
#[AsCommand(name: 'doctor', description: 'Checks the project: PHP, extensions, directories, environment variables, database, migrations, services')]
class DoctorCommand extends AbstractConsole
{
    public const LABELS = [
        CheckResult::OK => '<success>OK</success>     ',
        CheckResult::INFO => '<muted>INFO</muted>   ',
        CheckResult::WARNING => '<warning>WARNING</warning>',
        CheckResult::ERROR => '<error>ERROR</error>  ',
    ];

    public function __construct(
        protected DoctorManagerInterface $doctor,
        #[Autowire(param: 'kernel.environment')]
        protected string $environment = 'dev',
        #[Autowire(param: 'kernel.debug')]
        protected bool $debug = true,
    ) {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('check', 'c', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only this check (repeatable): php, application, database, migrations...');
        $input->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json', 'text');
        $input->addOption('strict', null, InputOption::VALUE_NONE, 'Fails on the warnings too (deployment, CI)');
        $this->setHelp(implode("\n", [
            'Runs every check of the enabled modules and of the application, then tells what to fix.',
            'Run it with --env=prod on the server: some checks only apply in production (debug, secrets, trusted hosts).',
            'The exit code is 1 when an error is found (or a warning with --strict), 0 otherwise.',
        ]));
        $this->addExample('doctor');
        $this->addExample('doctor --env=prod --strict');
        $this->addExample('doctor --check=database --check=migrations');
        $this->addExample('doctor --format=json');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');

        if (!in_array($format, ['text', 'json'], true)) {
            $output->error(sprintf('Unknown format "%s": use text or json.', $format));

            return self::FAILURE;
        }

        try {
            $report = $this->doctor->run(array_values(array_map('strval', (array) $input->getOption('check'))));
        } catch (DoctorException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $failed = $report->hasErrors() || ((bool) $input->getOption('strict') && $report->hasWarnings());

        if ($format === 'json') {
            $data = ['environment' => $this->environment, 'debug' => $this->debug, 'success' => !$failed] + $report->toArray();
            $output->write(Formatter::escape((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . PHP_EOL);

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $this->render($report, $output);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    protected function render(DoctorReport $report, OutputInterface $output): void
    {
        $output->newLine();
        $output->text(sprintf('Environment <info>%s</info>, debug <info>%s</info>', Formatter::escape($this->environment), $this->debug ? 'on' : 'off'));
        $width = 0;

        foreach ($report->getResults() as $results) {
            foreach ($results as $result) {
                $width = max($width, Formatter::width($result->getLabel()));
            }
        }

        foreach ($report->getResults() as $check => $results) {
            $output->newLine();
            $output->writeln(' <comment>' . Formatter::escape($check) . '</comment>');

            foreach ($results as $result) {
                $label = $result->getLabel() . str_repeat(' ', max(0, $width - Formatter::width($result->getLabel())));
                $output->writeln(sprintf('   %s  %s  %s', self::LABELS[$result->getStatus()], Formatter::escape($label), Formatter::escape($result->getMessage())));

                if ($result->getFix() !== null && !$result->isOk()) {
                    $output->writeln(sprintf('   %s  <muted>-> %s</muted>', str_repeat(' ', 7 + 2 + $width), Formatter::escape($result->getFix())));
                }
            }
        }

        $output->newLine();
        $summary = sprintf(
            '%d error(s), %d warning(s), %d OK, %d info.',
            $report->count(CheckResult::ERROR),
            $report->count(CheckResult::WARNING),
            $report->count(CheckResult::OK),
            $report->count(CheckResult::INFO),
        );

        match (true) {
            $report->hasErrors() => $output->error($summary),
            $report->hasWarnings() => $output->warning($summary),
            default => $output->success('Everything is fine: ' . $summary),
        };
    }
}