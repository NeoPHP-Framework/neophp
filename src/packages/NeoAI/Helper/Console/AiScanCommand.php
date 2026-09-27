<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Helper\Console;

use NeoPHP\Package\NeoAI\Contract\AbstractAiCommand;
use NeoPHP\Package\NeoAI\Exception\NeoAiException;
use NeoPHP\Package\NeoAI\Model\Finding;
use NeoPHP\Package\NeoAI\Scan\ReportWriter;
use NeoPHP\Package\NeoAI\Scan\Scanner;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\Formatter;
use NeoPHP\Process\Console\IO\InputOption;

#[AsCommand(name: 'ai:scan', description: 'Audits the project code with the NeoAI assistant and writes a Markdown report')]
class AiScanCommand extends AbstractAiCommand
{
    public const STYLES = ['critical' => 'error', 'high' => 'error', 'medium' => 'warning', 'low' => 'comment', 'info' => 'muted'];

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('path', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Paths to scan, relative to the project root (default: scan.paths)');
        $input->addOption('focus', null, InputOption::VALUE_REQUIRED, 'Focus of the audit: ' . implode(', ', Scanner::FOCUSES), 'all');
        $input->addOption('connection', null, InputOption::VALUE_REQUIRED, 'The connection to use (default: default_connection)');
        $input->addOption('max-files', null, InputOption::VALUE_REQUIRED, 'Maximum number of files to send (default: scan.max_files)');
        $input->addOption('output', null, InputOption::VALUE_REQUIRED, 'Report file (default: var/ai/reports/scan-YYYYmmdd-His.md)');
        $input->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Exit with code 1 when a finding has at least this severity: ' . implode(', ', array_keys(Finding::SEVERITIES)));
        $input->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list the files and batches, nothing is sent');
        $this->addExample('ai:scan');
        $this->addExample('ai:scan --focus=security --path=src/Controller');
        $this->addExample('ai:scan --connection=local --max-files=50 --fail-on=high');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $manager = $this->manager($output);

        if ($manager === null) {
            return self::FAILURE;
        }

        $focus = strtolower((string) ($input->getOption('focus') ?: 'all'));
        $failOn = $input->getOption('fail-on');

        if (!in_array($focus, Scanner::FOCUSES, true)) {
            $output->error(sprintf('Invalid focus "%s": %s.', $focus, implode(', ', Scanner::FOCUSES)));

            return self::INVALID;
        }

        if (is_string($failOn) && $failOn !== '' && Finding::rank($failOn) < 0) {
            $output->error(sprintf('Invalid --fail-on "%s": %s.', $failOn, implode(', ', array_keys(Finding::SEVERITIES))));

            return self::INVALID;
        }

        $connection = $input->getOption('connection');
        $maxFiles = $input->getOption('max-files');
        $paths = array_values(array_filter(array_map('strval', (array) $input->getOption('path')), static fn (string $path): bool => $path !== ''));

        try {
            $scanner = $manager->scanner(is_string($connection) && $connection !== '' ? $connection : null, ['max_files' => is_numeric($maxFiles) ? (int) $maxFiles : null]);
            $provider = $manager->connection(is_string($connection) && $connection !== '' ? $connection : null);
        } catch (NeoAiException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->title('NeoAI scan');
        $this->privacy($output, $provider);

        if ($input->getOption('dry-run')) {
            $collected = $scanner->collect($paths);
            $batches = $scanner->batches($collected['files']);
            $output->listing($collected['files']);
            $output->success(sprintf('%d file(s) in %d batch(es) would be sent (focus: %s).', count($collected['files']), count($batches), $focus));

            return self::SUCCESS;
        }

        try {
            $result = $scanner->scan($paths, $focus, static function (int $batch, int $total, array $files) use ($output): void {
                $output->writeln(sprintf(' <muted>Batch %d/%d: %d file(s)</muted>', $batch, $total, count($files)));
            });
        } catch (NeoAiException $exception) {
            $output->error($manager->redactor()->redact($exception->getMessage()));

            return self::FAILURE;
        }

        $this->report($output, $result);
        $writer = new ReportWriter();
        $file = $input->getOption('output');
        $file = is_string($file) && $file !== '' ? (preg_match('#^([a-zA-Z]:)?[/\\\\]#', $file) === 1 ? $file : $manager->getRoot() . DIRECTORY_SEPARATOR . $file) : $manager->getStoragePath('reports') . DIRECTORY_SEPARATOR . 'scan-' . date('Ymd-His') . '.md';

        try {
            $writer->write($file, $writer->markdown($result, basename($manager->getRoot())));
        } catch (NeoAiException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->success(sprintf('%d finding(s) in %d file(s), %d token(s). Report: %s', count($result['findings']), count($result['files']), $result['usage']['total'], $manager->sandbox()->relative($file)));

        if (is_string($failOn) && $failOn !== '') {
            $threshold = Finding::rank($failOn);

            foreach ($result['findings'] as $finding) {
                if ($finding->getRank() >= $threshold) {
                    $output->error(sprintf('At least one finding is %s or worse (--fail-on=%s).', $failOn, $failOn));

                    return self::FAILURE;
                }
            }
        }

        return self::SUCCESS;
    }

    protected function report(OutputInterface $output, array $result): void
    {
        foreach ($result['errors'] as $error) {
            $output->warning((string) $error);
        }

        if ($result['findings'] === []) {
            return;
        }

        $output->section('Findings');

        foreach ($result['findings'] as $finding) {
            $style = self::STYLES[$finding->getSeverity()];
            $output->writeln(sprintf(' <%1$s>[%2$s]</%1$s> <bold>%3$s</bold> <muted>%4$s</muted>', $style, strtoupper($finding->getSeverity()), Formatter::escape($finding->getTitle()), Formatter::escape($finding->getLocation())));

            if ($finding->getExplanation() !== '') {
                $output->writeln('   ' . Formatter::escape($finding->getExplanation()));
            }

            if ($finding->getFix() !== '') {
                $output->writeln('   <info>Fix:</info> ' . Formatter::escape($finding->getFix()));
            }
        }
    }
}