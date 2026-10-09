<?php

declare(strict_types=1);

namespace NeoPHP\Component\Database\Driver;

use NeoPHP\Component\Database\Contract\AbstractDriver;
use PDO;

class PgsqlDriver extends AbstractDriver
{
    public const DEFAULT_PORT = 5432;

    public const DEFAULT_DATABASE = 'postgres';

    public function getName(): string
    {
        return 'pgsql';
    }

    public function getExtension(): string
    {
        return 'pdo_pgsql';
    }

    public function getDsn(array $params, bool $withDatabase = true): string
    {
        return $this->buildDsn([
            'host' => $params['unix_socket'] ?? $params['host'] ?? '127.0.0.1',
            'port' => $params['port'] ?? self::DEFAULT_PORT,
            'dbname' => $withDatabase ? ($params['dbname'] ?? null) : ($params['default_dbname'] ?? self::DEFAULT_DATABASE),
            'sslmode' => $params['sslmode'] ?? null,
        ]);
    }

    protected function initialize(PDO $pdo, array $params): void
    {
        $charset = (string) ($params['charset'] ?? 'utf8');

        if ($charset !== '') {
            $pdo->exec('SET NAMES ' . $pdo->quote($charset));
        }
    }

    protected function getDatabaseExistsSql(): string
    {
        return 'SELECT datname FROM pg_database WHERE datname = ?';
    }

    protected function getCreateDatabaseSql(string $name, array $params): string
    {
        $sql = 'CREATE DATABASE ' . $this->quoteIdentifier($name);
        $charset = preg_replace('/\W/', '', (string) ($params['charset'] ?? ''));

        if ($charset !== '') {
            $sql .= " ENCODING '" . strtoupper($charset) . "'";
        }

        return $sql;
    }
}