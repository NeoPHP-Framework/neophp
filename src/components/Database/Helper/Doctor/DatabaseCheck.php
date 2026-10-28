<?php

declare(strict_types=1);

namespace NeoPHP\Component\Database\Helper\Doctor;

use NeoPHP\Component\Database\DatabaseManagerInterface;
use NeoPHP\Process\Doctor\Contract\CheckInterface;
use NeoPHP\Process\Doctor\Model\CheckResult;
use Throwable;

/**
 * @internal
 */
class DatabaseCheck implements CheckInterface
{
    public function __construct(protected DatabaseManagerInterface $database)
    {
    }

    public function getName(): string
    {
        return 'Database';
    }

    public function run(): iterable
    {
        $names = $this->database->getConnectionNames();

        if ($names === []) {
            yield CheckResult::info('Connections', 'none configured', 'Set DATABASE_URL in .env to use a database.');

            return;
        }

        foreach (array_map('strval', $names) as $name) {
            $label = 'Connection ' . $name;

            try {
                $params = $this->database->getParams($name);
                $driver = $this->database->getDriver((string) $params['driver']);
            } catch (Throwable $exception) {
                yield CheckResult::error($label, $exception->getMessage(), 'Check the url of the connection in config/framework/database.yaml (DATABASE_URL in .env).');

                continue;
            }

            if (!$driver->isAvailable()) {
                yield CheckResult::error($label, sprintf('the PHP extension %s is missing', $driver->getExtension()), sprintf('Install and enable the PHP extension %s.', $driver->getExtension()));

                continue;
            }

            try {
                $connection = $this->database->connection($name);
                $connection->fetchOne('SELECT 1');

                yield CheckResult::ok($label, sprintf('%s %s%s', $driver->getName(), $connection->getServerVersion(), $connection->getDatabase() !== null ? ', ' . basename((string) $connection->getDatabase()) : ''));
            } catch (Throwable $exception) {
                yield CheckResult::error($label, $exception->getMessage(), 'Check that the server is running, the credentials, and that the database exists (php bin/neo database:create).');
            }
        }
    }
}