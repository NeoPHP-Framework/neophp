<?php

declare(strict_types=1);

namespace NeoPHP\Component\Database\Helper\Console;

use NeoPHP\Component\Database\DatabaseManagerInterface;
use NeoPHP\Component\Database\Exception\DatabaseException;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputOption;

/**
 * @internal
 */
#[AsCommand(name: 'database:drop', description: 'Drops the configured database and all its data', aliases: ['db:drop'])]
class DatabaseDropCommand extends AbstractConsole
{
    public function __construct(protected DatabaseManagerInterface $database)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'The connection to use (default: the default connection)');
        $input->addOption('if-exists', null, InputOption::VALUE_NONE, 'Do not fail when the database does not exist');
        $this->setHelp('The command asks for a confirmation. Use --force to skip it (required with --no-interaction).');
        $this->addExample('database:drop');
        $this->addExample('database:drop --force --if-exists');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $connection = $input->getOption('connection');
        $name = is_string($connection) && $connection !== '' ? $connection : $this->database->getDefaultConnectionName();

        try {
            $params = $this->database->getParams($name);
            $driver = $this->database->getDriver((string) $params['driver']);
            $label = sprintf('"%s" (%s, connection "%s")', $this->database->connection($name)->getDatabase() ?? '', $driver->getName(), $name);

            if (!$input->getOption('force')) {
                $output->caution(sprintf('This will delete the database %s and all its data.', $label));

                if (!$output->isInteractive()) {
                    $output->text('Run the command again with <info>--force</info> to confirm.');

                    return self::INVALID;
                }

                if (!$output->confirm('Do you really want to drop the database?', false)) {
                    $output->note('Aborted.');

                    return self::SUCCESS;
                }
            }

            $this->database->close($name);

            if ($params['memory'] ?? false) {
                $output->note(sprintf('Nothing to drop for the in-memory database %s.', $label));

                return self::SUCCESS;
            }

            if (!$driver->databaseExists($params)) {
                if ($input->getOption('if-exists')) {
                    $output->note(sprintf('The database %s does not exist: skipped.', $label));

                    return self::SUCCESS;
                }

                $output->error(sprintf('The database %s does not exist.', $label));

                return self::FAILURE;
            }

            $driver->dropDatabase($params);
        } catch (DatabaseException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->success(sprintf('Database %s dropped.', $label));

        return self::SUCCESS;
    }
}