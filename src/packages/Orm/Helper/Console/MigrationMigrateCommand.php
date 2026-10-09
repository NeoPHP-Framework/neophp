<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Helper\Console;

use NeoPHP\Package\Orm\Contract\MigrationInterface;
use NeoPHP\Package\Orm\Migration\Migrator;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\Formatter;
use NeoPHP\Process\Console\IO\InputOption;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'migration:migrate', description: 'Executes the migrations not executed yet', aliases: ['migrate'])]
class MigrationMigrateCommand extends AbstractConsole
{
    public function __construct(protected Migrator $migrator)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the SQL statements without executing them');
        $this->addExample('migration:migrate');
        $this->addExample('migration:migrate --dry-run');
        $this->addExample('migration:migrate -v');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            if ($this->migrator->getPending() === []) {
                $output->note('Already up to date: no migration to execute.');

                return self::SUCCESS;
            }

            $executed = $this->migrator->migrate($dryRun, static function (MigrationInterface $migration, string $direction, array $statements) use ($output, $dryRun): void {
                $output->writeln(sprintf('  <info>up</info>  Migration_%s %s<muted>(%d statement(s))</muted>', $migration->getVersion(), $migration->getDescription() !== '' ? $migration->getDescription() . ' ' : '', count($statements)));

                foreach ($statements as [$sql]) {
                    $output->writeln('      <muted>' . Formatter::escape((string) $sql) . ';</muted>', $dryRun ? OutputInterface::VERBOSITY_NORMAL : OutputInterface::VERBOSITY_VERBOSE);
                }
            });
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($dryRun) {
            $output->note(sprintf('Dry run: %d migration(s) not executed.', count($executed)));
        } else {
            $output->success(sprintf('%d migration(s) executed.', count($executed)));
        }

        return self::SUCCESS;
    }
}