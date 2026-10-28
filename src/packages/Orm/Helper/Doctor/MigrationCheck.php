<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Helper\Doctor;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Package\Orm\Migration\Migrator;
use NeoPHP\Process\Doctor\Contract\CheckInterface;
use NeoPHP\Process\Doctor\Model\CheckResult;
use Throwable;

/**
 * @internal
 */
class MigrationCheck implements CheckInterface
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    public function getName(): string
    {
        return 'Migrations';
    }

    public function run(): iterable
    {
        try {
            $migrator = $this->container->get(Migrator::class);
            $migrations = $migrator->getMigrations();
            $pending = $migrations === [] ? 0 : count($migrator->getPending());
        } catch (Throwable $exception) {
            yield CheckResult::warning('Migrations', 'cannot be read: ' . $exception->getMessage(), 'Fix the connection first (see Database).');

            return;
        }

        if ($migrations === []) {
            yield CheckResult::info('Migrations', 'none', 'Generate one with php bin/neo make:migration after creating your entities.');

            return;
        }

        yield $pending === 0
            ? CheckResult::ok('Migrations', sprintf('%d executed', count($migrations)))
            : CheckResult::warning('Migrations', sprintf('%d of %d not executed', $pending, count($migrations)), 'Run php bin/neo migration:migrate (php bin/neo migration:status lists them).');
    }
}