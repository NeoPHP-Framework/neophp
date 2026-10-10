<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Migration;

use NeoPHP\Component\Database\Contract\ConnectionInterface;
use NeoPHP\Package\Orm\Contract\AbstractMigration;
use NeoPHP\Package\Orm\Contract\MigrationInterface;
use NeoPHP\Package\Orm\Contract\PlatformInterface;
use NeoPHP\Package\Orm\Exception\MigrationException;
use NeoPHP\Package\Orm\Schema\Column;
use NeoPHP\Package\Orm\Schema\Table;
use Throwable;

class Migrator
{
    public const UP = 'up';

    public const DOWN = 'down';

    protected ?array $migrations = null;

    protected array $sources = [];

    protected array $origins = [];

    public function __construct(
        protected ConnectionInterface $connection,
        protected PlatformInterface $platform,
        protected string $directory,
        protected string $namespace = 'Migrations',
        protected string $table = 'neo_migrations',
    ) {
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function addSource(string $directory, string $namespace, string $source): static
    {
        $this->sources[] = ['directory' => $directory, 'namespace' => $namespace, 'source' => $source];
        $this->migrations = null;

        return $this;
    }

    public function getSources(): array
    {
        return $this->sources;
    }

    public function getSource(string $version): ?string
    {
        $this->getMigrations();

        return $this->origins[$version] ?? null;
    }

    public function getMigrations(): array
    {
        if ($this->migrations !== null) {
            return $this->migrations;
        }

        $migrations = [];
        $this->origins = [];

        foreach ([['directory' => $this->directory, 'namespace' => $this->namespace, 'source' => null], ...$this->sources] as $source) {
            foreach ($this->load((string) $source['directory'], (string) $source['namespace']) as $version => $class) {
                if (isset($migrations[$version])) {
                    throw new MigrationException('The migration "{version}" is defined twice: in "{first}" and in "{second}".', 0, null, [
                        'version' => $version,
                        'first' => $this->origins[$version] ?? 'the application',
                        'second' => $source['source'] ?? 'the application',
                    ]);
                }

                $migrations[$version] = $class;
                $this->origins[$version] = $source['source'];
            }
        }

        ksort($migrations, SORT_STRING);

        return $this->migrations = $migrations;
    }

    protected function load(string $directory, string $namespace): array
    {
        $migrations = [];

        foreach (glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . AbstractMigration::PREFIX . '*.php') ?: [] as $file) {
            $version = substr(basename($file, '.php'), strlen(AbstractMigration::PREFIX));
            $class = rtrim($namespace, '\\') . '\\' . AbstractMigration::PREFIX . $version;

            if (!class_exists($class, false)) {
                require_once $file;
            }

            if (!class_exists($class, false) || !is_subclass_of($class, MigrationInterface::class)) {
                throw new MigrationException('The file "{file}" must declare the class "{class}" implementing {interface}.', 0, null, [
                    'file' => $file,
                    'class' => $class,
                    'interface' => MigrationInterface::class,
                ]);
            }

            $migrations[(string) $version] = $class;
        }

        return $migrations;
    }

    public function getExecuted(): array
    {
        if (!$this->hasTable()) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(sprintf('SELECT * FROM %s ORDER BY %s', $this->platform->quoteIdentifier($this->table), $this->platform->quoteIdentifier('version')));
        $executed = [];

        foreach ($rows as $row) {
            $executed[(string) $row['version']] = $row;
        }

        return $executed;
    }

    public function getPending(): array
    {
        return array_diff_key($this->getMigrations(), $this->getExecuted());
    }

    public function getStatus(): array
    {
        $executed = $this->getExecuted();
        $status = [];

        foreach ($this->getMigrations() as $version => $class) {
            $status[$version] = [
                'version' => $version,
                'description' => $this->create($class)->getDescription(),
                'executed_at' => $executed[$version]['executed_at'] ?? null,
                'execution_time' => $executed[$version]['execution_time'] ?? null,
                'available' => true,
                'source' => $this->origins[$version] ?? null,
            ];
        }

        foreach ($executed as $version => $row) {
            if (!isset($status[$version])) {
                $status[$version] = ['version' => $version, 'description' => '', 'executed_at' => $row['executed_at'], 'execution_time' => $row['execution_time'], 'available' => false, 'source' => null];
            }
        }

        ksort($status, SORT_STRING);

        return $status;
    }

    public function migrate(bool $dryRun = false, ?callable $logger = null): array
    {
        $executed = [];

        foreach ($this->getPending() as $version => $class) {
            $this->execute($class, self::UP, $dryRun, $logger);
            $executed[] = (string) $version;
        }

        return $executed;
    }

    public function rollback(int $steps = 1, bool $dryRun = false, ?callable $logger = null): array
    {
        $migrations = $this->getMigrations();
        $versions = array_reverse(array_keys($this->getExecuted()));
        $rolledBack = [];

        foreach (array_slice($versions, 0, max(1, $steps)) as $version) {
            if (!isset($migrations[$version])) {
                throw new MigrationException('Unable to roll back the migration "{version}": its file is missing.', 0, null, ['version' => $version]);
            }

            $this->execute($migrations[$version], self::DOWN, $dryRun, $logger);
            $rolledBack[] = (string) $version;
        }

        return $rolledBack;
    }

    public function execute(string $class, string $direction, bool $dryRun = false, ?callable $logger = null): array
    {
        $migration = $this->create($class);
        $migration->clearSql();
        $direction === self::DOWN ? $migration->down() : $migration->up();
        $statements = $migration->getSql();

        if ($logger !== null) {
            $logger($migration, $direction, $statements);
        }

        if ($dryRun) {
            return $statements;
        }

        $this->ensureTable();
        $sqlite = $this->platform->getName() === 'sqlite';
        $transactional = $migration->isTransactional() && $this->platform->supportsTransactionalDdl();
        $start = microtime(true);

        if ($sqlite) {
            $this->connection->executeStatement('PRAGMA foreign_keys = OFF');
        }

        try {
            if ($transactional) {
                $this->connection->beginTransaction();
            }

            foreach ($statements as [$sql, $params]) {
                $this->connection->executeStatement($sql, $params);
            }

            if ($direction === self::UP) {
                $this->connection->insert($this->table, [
                    'version' => $migration->getVersion(),
                    'executed_at' => date('Y-m-d H:i:s'),
                    'execution_time' => (int) round((microtime(true) - $start) * 1000),
                ]);
            } else {
                $this->connection->delete($this->table, ['version' => $migration->getVersion()]);
            }

            if ($transactional) {
                $this->connection->commit();
            }
        } catch (Throwable $exception) {
            if ($transactional && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw new MigrationException('The migration {version} failed ({direction}): {error}', 0, $exception, [
                'version' => $migration->getVersion(),
                'direction' => $direction,
                'error' => $exception->getMessage(),
            ]);
        } finally {
            if ($sqlite) {
                $this->connection->executeStatement('PRAGMA foreign_keys = ON');
            }
        }

        return $statements;
    }

    public function create(string $class): MigrationInterface
    {
        return new $class($this->connection, $this->platform);
    }

    public function hasTable(): bool
    {
        return $this->platform->introspect($this->connection)->hasTable($this->table);
    }

    public function ensureTable(): void
    {
        if ($this->hasTable()) {
            return;
        }

        $table = new Table($this->table);
        $table->addColumn(new Column('version', 'string', 191));
        $table->addColumn(new Column('executed_at', 'datetime', null, true));
        $table->addColumn(new Column('execution_time', 'integer', null, true));
        $table->setPrimaryKey(['version']);

        foreach ($this->platform->getCreateTableSql($table) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }
}