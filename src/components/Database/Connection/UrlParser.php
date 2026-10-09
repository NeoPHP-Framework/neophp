<?php

declare(strict_types=1);

namespace NeoPHP\Component\Database\Connection;

use NeoPHP\Component\Database\Exception\DatabaseException;

class UrlParser
{
    public const SCHEMES = [
        'mysql' => 'mysql',
        'mysql2' => 'mysql',
        'mariadb' => 'mysql',
        'pgsql' => 'pgsql',
        'postgres' => 'pgsql',
        'postgresql' => 'pgsql',
        'sqlite' => 'sqlite',
        'sqlite3' => 'sqlite',
    ];

    public function parse(string $url): array
    {
        if (preg_match('#^([a-z][a-z0-9+.-]*)://(.*)$#is', trim($url), $m) !== 1) {
            throw new DatabaseException('The database URL "{url}" is not valid: expected "driver://...".', 0, null, ['url' => $url]);
        }

        $scheme = strtolower($m[1]);

        if (!isset(self::SCHEMES[$scheme])) {
            throw new DatabaseException('The database URL scheme "{scheme}" is not supported. Supported schemes: {schemes}.', 0, null, [
                'scheme' => $scheme,
                'schemes' => implode(', ', array_keys(self::SCHEMES)),
            ]);
        }

        $driver = self::SCHEMES[$scheme];

        return $driver === 'sqlite' ? $this->parseSqlite($m[2]) : $this->parseServer($driver, $url);
    }

    protected function parseServer(string $driver, string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false) {
            throw new DatabaseException('The database URL "{url}" is not valid.', 0, null, ['url' => $url]);
        }

        $params = ['driver' => $driver];

        if (isset($parts['host']) && $parts['host'] !== '') {
            $params['host'] = rawurldecode($parts['host']);
        }

        if (isset($parts['port'])) {
            $params['port'] = (int) $parts['port'];
        }

        if (isset($parts['user'])) {
            $params['user'] = rawurldecode($parts['user']);
        }

        if (isset($parts['pass'])) {
            $params['password'] = rawurldecode($parts['pass']);
        }

        $dbname = rawurldecode(ltrim((string) ($parts['path'] ?? ''), '/'));

        if ($dbname !== '') {
            $params['dbname'] = $dbname;
        }

        return $params + $this->parseQuery((string) ($parts['query'] ?? ''));
    }

    protected function parseSqlite(string $rest): array
    {
        $query = '';

        if (($position = strpos($rest, '?')) !== false) {
            $query = substr($rest, $position + 1);
            $rest = substr($rest, 0, $position);
        }

        $path = str_starts_with($rest, '/') ? substr($rest, 1) : $rest;
        $path = rawurldecode($path);
        $params = ['driver' => 'sqlite'];

        if ($path === ':memory:' || $path === 'memory' || $path === '') {
            $params['memory'] = true;
        } else {
            $params['path'] = $path;
        }

        return $params + $this->parseQuery($query);
    }

    protected function parseQuery(string $query): array
    {
        if ($query === '') {
            return [];
        }

        parse_str($query, $values);

        return $values;
    }
}