<?php

declare(strict_types=1);

namespace NeoPHP\Component\Database\Driver;

use NeoPHP\Component\Database\Contract\AbstractDriver;
use NeoPHP\Component\Database\Exception\DatabaseException;
use PDO;

class SqliteDriver extends AbstractDriver
{
    public const MEMORY = ':memory:';

    public function getName(): string
    {
        return 'sqlite';
    }

    public function getExtension(): string
    {
        return 'pdo_sqlite';
    }

    public function getDsn(array $params, bool $withDatabase = true): string
    {
        return 'sqlite:' . $this->getPath($params);
    }

    public function connect(array $params, bool $withDatabase = true): PDO
    {
        return parent::connect(['user' => null, 'password' => null] + $params, $withDatabase);
    }

    public function databaseExists(array $params): bool
    {
        return $this->isMemory($params) || is_file($this->getPath($params));
    }

    public function createDatabase(array $params): bool
    {
        if ($this->databaseExists($params)) {
            return false;
        }

        $path = $this->getPath($params);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new DatabaseException('Unable to create the directory "{directory}".', 0, null, ['directory' => $directory]);
        }

        $this->connect($params)->exec('PRAGMA user_version = 0');

        return is_file($path);
    }

    public function dropDatabase(array $params): bool
    {
        if ($this->isMemory($params) || !$this->databaseExists($params)) {
            return false;
        }

        $path = $this->getPath($params);

        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            if (is_file($path . $suffix)) {
                @unlink($path . $suffix);
            }
        }

        if (!@unlink($path)) {
            throw new DatabaseException('Unable to delete the database file "{file}".', 0, null, ['file' => $path]);
        }

        return true;
    }

    public function getPath(array $params): string
    {
        if (!empty($params['memory'])) {
            return self::MEMORY;
        }

        $path = (string) ($params['path'] ?? $params['dbname'] ?? '');

        if ($path === '') {
            throw new DatabaseException('No database file ("path") is configured for the "sqlite" connection.');
        }

        return $path;
    }

    protected function isMemory(array $params): bool
    {
        return $this->getPath($params) === self::MEMORY;
    }

    protected function initialize(PDO $pdo, array $params): void
    {
        if (($params['foreign_keys'] ?? true) !== false) {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    protected function getDatabaseExistsSql(): string
    {
        return '';
    }

    protected function getCreateDatabaseSql(string $name, array $params): string
    {
        return '';
    }
}