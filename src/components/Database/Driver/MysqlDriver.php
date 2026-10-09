<?php

declare(strict_types=1);

namespace NeoPHP\Component\Database\Driver;

use NeoPHP\Component\Database\Contract\AbstractDriver;

class MysqlDriver extends AbstractDriver
{
    public const IDENTIFIER_QUOTE = '`';

    public const DEFAULT_PORT = 3306;

    public function getName(): string
    {
        return 'mysql';
    }

    public function getExtension(): string
    {
        return 'pdo_mysql';
    }

    public function getDsn(array $params, bool $withDatabase = true): string
    {
        $socket = $params['unix_socket'] ?? null;

        return $this->buildDsn([
            'host' => $socket ? null : ($params['host'] ?? '127.0.0.1'),
            'port' => $socket ? null : ($params['port'] ?? self::DEFAULT_PORT),
            'unix_socket' => $socket,
            'dbname' => $withDatabase ? ($params['dbname'] ?? null) : null,
            'charset' => $params['charset'] ?? 'utf8mb4',
        ]);
    }

    protected function getDatabaseExistsSql(): string
    {
        return 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?';
    }

    protected function getCreateDatabaseSql(string $name, array $params): string
    {
        $sql = 'CREATE DATABASE ' . $this->quoteIdentifier($name) . ' CHARACTER SET ' . preg_replace('/\W/', '', (string) ($params['charset'] ?? 'utf8mb4'));

        if (!empty($params['collation'])) {
            $sql .= ' COLLATE ' . preg_replace('/\W/', '', (string) $params['collation']);
        }

        return $sql;
    }
}