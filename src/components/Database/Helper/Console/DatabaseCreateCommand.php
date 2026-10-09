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
#[AsCommand(name: 'database:create', description: 'Creates the configured database', aliases: ['db:create'])]
class DatabaseCreateCommand extends AbstractConsole
{
    public function __construct(protected DatabaseManagerInterface $database)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'The connection to use (default: the default connection)');
        $input->addOption('if-not-exists', null, InputOption::VALUE_NONE, 'Do not fail when the database already exists');
        $this->addExample('database:create');
        $this->addExample('database:create --connection=logs --if-not-exists');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $connection = $input->getOption('connection');
        $name = is_string($connection) && $connection !== '' ? $connection : $this->database->getDefaultConnectionName();

        try {
            $params = $this->database->getParams($name);
            $driver = $this->database->getDriver((string) $params['driver']);
            $label = sprintf('"%s" (%s, connection "%s")', $this->database->connection($name)->getDatabase() ?? '', $driver->getName(), $name);

            if (!($params['memory'] ?? false) && $driver->databaseExists($params)) {
                if ($input->getOption('if-not-exists')) {
                    $output->note(sprintf('The database %s already exists: skipped.', $label));

                    return self::SUCCESS;
                }

                $output->error(sprintf('The database %s already exists.', $label));

                return self::FAILURE;
            }

            if (!$driver->createDatabase($params)) {
                $output->note(sprintf('Nothing to create for the database %s.', $label));

                return self::SUCCESS;
            }
        } catch (DatabaseException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->success(sprintf('Database %s created.', $label));

        return self::SUCCESS;
    }
}