<?php

declare(strict_types=1);

namespace NeoPHP\Component\Database\Helper\Console;

use NeoPHP\Component\Database\DatabaseManagerInterface;
use NeoPHP\Component\Database\Exception\DatabaseException;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\Formatter;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\IO\InputOption;

/**
 * @internal
 */
#[AsCommand(name: 'database:query', description: 'Executes a SQL query and displays the result', aliases: ['db:query'])]
class DatabaseQueryCommand extends AbstractConsole
{
    public const MAX_WIDTH = 60;

    public function __construct(protected DatabaseManagerInterface $database)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('sql', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'The SQL query (the words are joined with a space)', null, 'SQL query');
        $input->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'The connection to use (default: the default connection)');
        $this->addExample('database:query "SELECT * FROM user"');
        $this->addExample('database:query "DELETE FROM session" --connection=logs');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $sql = trim(implode(' ', (array) $input->getArgument('sql')));

        if ($sql === '') {
            $output->error('The SQL query is empty.');

            return self::INVALID;
        }

        $connection = $input->getOption('connection');
        $name = is_string($connection) && $connection !== '' ? $connection : null;
        $output->writeln('<muted>' . Formatter::escape($sql) . '</muted>', OutputInterface::VERBOSITY_VERBOSE);

        try {
            $result = $this->database->connection($name)->executeQuery($sql);

            if ($result->columnCount() === 0) {
                $output->success(sprintf('Query executed. %d row(s) affected.', $result->rowCount()));

                return self::SUCCESS;
            }

            $columns = $result->getColumnNames();
            $rows = array_map(fn (array $row): array => array_map(fn (mixed $value): string => $this->format($value), $row), $result->fetchAllNumeric());
        } catch (DatabaseException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($rows === []) {
            $output->note('No rows.');

            return self::SUCCESS;
        }

        $output->table($columns, $rows);
        $output->text(sprintf('%d row(s)', count($rows)));

        return self::SUCCESS;
    }

    protected function format(mixed $value): string
    {
        $value = match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? 'true' : 'false',
            is_resource($value) => (string) stream_get_contents($value),
            default => (string) $value,
        };

        $value = str_replace(["\r\n", "\n", "\r", "\t"], ' ', $value);

        return Formatter::escape(mb_strlen($value) > self::MAX_WIDTH ? mb_substr($value, 0, self::MAX_WIDTH - 3) . '...' : $value);
    }
}