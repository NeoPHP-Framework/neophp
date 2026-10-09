<?php

declare(strict_types=1);

namespace NeoPHP\Component\Database\Contract;

use NeoPHP\Component\Database\Exception\ConnectionException;
use NeoPHP\Component\Database\Exception\DatabaseException;
use PDO;
use PDOException;

abstract class AbstractDriver implements DriverInterface
{
    public const DEFAULT_OPTIONS = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ];

    public const IDENTIFIER_QUOTE = '"';

    public function isAvailable(): bool
    {
        return extension_loaded('pdo') && in_array($this->getName(), PDO::getAvailableDrivers(), true);
    }

    public function connect(array $params, bool $withDatabase = true): PDO
    {
        if (!$this->isAvailable()) {
            throw new ConnectionException('The PHP extension "{extension}" is required to use the "{driver}" driver.', 0, null, [
                'extension' => $this->getExtension(),
                'driver' => $this->getName(),
            ]);
        }

        try {
            $pdo = new PDO($this->getDsn($params, $withDatabase), $params['user'] ?? null, $params['password'] ?? null, $this->getOptions($params));
        } catch (PDOException $exception) {
            throw new ConnectionException('Unable to connect to the "{driver}" database: {error}', 0, $exception, [
                'driver' => $this->getName(),
                'error' => $exception->getMessage(),
            ]);
        }

        $this->initialize($pdo, $params);

        return $pdo;
    }

    public function quoteIdentifier(string $identifier): string
    {
        $quote = static::IDENTIFIER_QUOTE;

        return implode('.', array_map(
            static fn (string $part): string => $part === '*' ? $part : $quote . str_replace($quote, $quote . $quote, $part) . $quote,
            explode('.', $identifier),
        ));
    }

    public function databaseExists(array $params): bool
    {
        $statement = $this->connect($params, false)->prepare($this->getDatabaseExistsSql());
        $statement->execute([$this->getDatabaseName($params)]);

        return $statement->fetchColumn() !== false;
    }

    public function createDatabase(array $params): bool
    {
        if ($this->databaseExists($params)) {
            return false;
        }

        $this->connect($params, false)->exec($this->getCreateDatabaseSql($this->getDatabaseName($params), $params));

        return true;
    }

    public function dropDatabase(array $params): bool
    {
        if (!$this->databaseExists($params)) {
            return false;
        }

        $this->connect($params, false)->exec('DROP DATABASE ' . $this->quoteIdentifier($this->getDatabaseName($params)));

        return true;
    }

    protected function getOptions(array $params): array
    {
        $options = static::DEFAULT_OPTIONS;

        foreach ((array) ($params['options'] ?? []) as $name => $value) {
            $options[$this->resolveOption($name)] = is_string($value) && str_starts_with($value, 'PDO::') && defined($value) ? constant($value) : $value;
        }

        return $options;
    }

    protected function resolveOption(int|string $name): int
    {
        if (is_int($name) || ctype_digit($name)) {
            return (int) $name;
        }

        $constant = str_starts_with($name, 'PDO::') ? $name : 'PDO::' . strtoupper($name);

        if (!defined($constant)) {
            throw new DatabaseException('Unknown PDO option "{option}".', 0, null, ['option' => $name]);
        }

        return (int) constant($constant);
    }

    protected function initialize(PDO $pdo, array $params): void
    {
    }

    protected function getDatabaseName(array $params): string
    {
        $name = (string) ($params['dbname'] ?? '');

        if ($name === '') {
            throw new DatabaseException('No database name ("dbname") is configured for the "{driver}" connection.', 0, null, ['driver' => $this->getName()]);
        }

        return $name;
    }

    protected function buildDsn(array $parts): string
    {
        $pairs = [];

        foreach ($parts as $key => $value) {
            if ($value !== null && $value !== '') {
                $pairs[] = $key . '=' . $value;
            }
        }

        return $this->getName() . ':' . implode(';', $pairs);
    }

    abstract protected function getDatabaseExistsSql(): string;

    abstract protected function getCreateDatabaseSql(string $name, array $params): string;
}