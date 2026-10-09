<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Helper\Console;

use NeoPHP\Package\Orm\Contract\MigrationInterface;
use NeoPHP\Package\Orm\Migration\Migrator;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\Exception\InvalidInputException;
use NeoPHP\Process\Console\IO\Formatter;
use NeoPHP\Process\Console\IO\InputOption;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'migration:rollback', description: 'Rolls back the last executed migrations (down)', aliases: ['rollback'])]
class MigrationRollbackCommand extends AbstractConsole
{
    public function __construct(protected Migrator $migrator)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('steps', 's', InputOption::VALUE_REQUIRED, 'The number of migrations to roll back', 1);
        $input->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the SQL statements without executing them');
        $this->addExample('migration:rollback');
        $this->addExample('migration:rollback --steps=3');
        $this->addExample('migration:rollback -s 2 --dry-run');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $steps = $input->getOption('steps');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!is_numeric($steps) || (int) $steps < 1) {
            throw new InvalidInputException('The "--steps" option must be a positive integer, "{value}" given.', 0, null, ['value' => (string) $steps]);
        }

        try {
            if ($this->migrator->getExecuted() === []) {
                $output->note('No migration to roll back.');

                return self::SUCCESS;
            }

            $rolledBack = $this->migrator->rollback((int) $steps, $dryRun, static function (MigrationInterface $migration, string $direction, array $statements) use ($output, $dryRun): void {
                $output->writeln(sprintf('  <comment>down</comment>  Migration_%s %s<muted>(%d statement(s))</muted>', $migration->getVersion(), $migration->getDescription() !== '' ? $migration->getDescription() . ' ' : '', count($statements)));

                foreach ($statements as [$sql]) {
                    $output->writeln('        <muted>' . Formatter::escape((string) $sql) . ';</muted>', $dryRun ? OutputInterface::VERBOSITY_NORMAL : OutputInterface::VERBOSITY_VERBOSE);
                }
            });
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($dryRun) {
            $output->note(sprintf('Dry run: %d migration(s) not rolled back.', count($rolledBack)));
        } else {
            $output->success(sprintf('%d migration(s) rolled back.', count($rolledBack)));
        }

        return self::SUCCESS;
    }
}