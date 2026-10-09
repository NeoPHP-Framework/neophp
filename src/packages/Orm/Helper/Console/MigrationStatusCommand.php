<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Helper\Console;

use NeoPHP\Package\Orm\Migration\Migrator;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'migration:status', description: 'Lists the migrations and whether they are executed')]
class MigrationStatusCommand extends AbstractConsole
{
    public function __construct(protected Migrator $migrator)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $this->addExample('migration:status');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        try {
            $status = $this->migrator->getStatus();
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->title('Migrations');
        $output->text(sprintf('%s <muted>(table %s)</muted>', $this->migrator->getDirectory(), $this->migrator->getTable()));
        $output->newLine();

        if ($status === []) {
            $output->note('No migration found. Generate one with: php bin/neo make:migration');

            return self::SUCCESS;
        }

        $rows = [];
        $pending = 0;

        foreach ($status as $migration) {
            $state = match (true) {
                !$migration['available'] => '<error>missing file</error>',
                $migration['executed_at'] !== null => '<success>executed</success>',
                default => '<comment>pending</comment>',
            };
            $pending += $migration['available'] && $migration['executed_at'] === null ? 1 : 0;
            $rows[] = [
                'Migration_' . $migration['version'],
                $migration['description'],
                $state,
                (string) ($migration['executed_at'] ?? ''),
                $migration['execution_time'] !== null ? $migration['execution_time'] . ' ms' : '',
            ];
        }

        $output->table(['Migration', 'Description', 'Status', 'Executed at', 'Time'], $rows);
        $output->text(sprintf('%d migration(s), %d pending.', count($status), $pending));

        return self::SUCCESS;
    }
}