<?php

declare(strict_types=1);

namespace NeoPHP\Component\Database;

use Closure;
use NeoPHP\Component\Database\Contract\ConnectionInterface;
use NeoPHP\Component\Database\Contract\DriverInterface;
use NeoPHP\Component\Database\Contract\QueryLoggerInterface;
use NeoPHP\Component\Database\Exception\DatabaseException;

interface DatabaseManagerInterface
{
    /**
     * Returns a connection, created on first use from its configuration.
     *
     * @param string|null $name Name of the connection, the default connection when null
     * @return ConnectionInterface The connection
     * @throws DatabaseException When the connection is not configured, has no url nor driver, its URL is invalid or its driver is not supported
     */
    public function connection(?string $name = null): ConnectionInterface;

    /**
     * Tells whether a connection is configured.
     *
     * @param string $name Name of the connection
     * @return bool True when the connection is configured
     */
    public function hasConnection(string $name): bool;

    /**
     * Adds or replaces the configuration of a connection; an open connection of the same name is forgotten.
     *
     * @param string $name Name of the connection
     * @param array<string, mixed>|string $config A database URL (mysql://user:password@host/name) or the parameters: url, driver, host, port, dbname, user, password, path, charset...
     * @return static The database manager
     */
    public function addConnection(string $name, array|string $config): static;

    /**
     * Returns the names of the configured connections.
     *
     * @return list<int|string> The names of the connections
     */
    public function getConnectionNames(): array;

    /**
     * Returns the name of the default connection.
     *
     * @return string The name of the default connection
     */
    public function getDefaultConnectionName(): string;

    /**
     * Returns the connections already created.
     *
     * @return array<string, ConnectionInterface> The connections, by name
     */
    public function getConnections(): array;

    /**
     * Returns the parameters of a connection: its URL parsed and merged with the other keys, the driver normalized and a relative SQLite path made absolute.
     *
     * @param string|null $name Name of the connection, the default connection when null
     * @return array<mixed> The parameters of the connection, by name
     * @throws DatabaseException When the connection is not configured, has no url nor driver, or its URL is invalid
     */
    public function getParams(?string $name = null): array;

    /**
     * Returns a driver, built on first use.
     *
     * @param string $name Name of the driver: mysql, pgsql, sqlite, an alias (pdo_mysql, postgresql...) or a driver added with addDriver()
     * @return DriverInterface The driver
     * @throws DatabaseException When the driver is not supported or does not implement DriverInterface
     */
    public function getDriver(string $name): DriverInterface;

    /**
     * Registers a driver.
     *
     * @param string $name Name of the driver
     * @param DriverInterface|string $driver The driver, or its class name built on first use
     * @return static The database manager
     */
    public function addDriver(string $name, DriverInterface|string $driver): static;

    /**
     * Closes the open connections.
     *
     * @param string|null $name Name of the connection to close, every connection when null
     * @return void
     */
    public function close(?string $name = null): void;

    /**
     * Sets the query logger of the current and future connections.
     *
     * @param QueryLoggerInterface|null $logger The query logger, or null to disable the logging
     * @return static The database manager
     */
    public function setQueryLogger(?QueryLoggerInterface $logger): static;

    /**
     * Sets a closure returning the query logger, called once on the first use of the logger.
     *
     * @param Closure|null $resolver A closure returning a QueryLoggerInterface or null
     * @return static The database manager
     */
    public function setQueryLoggerResolver(?Closure $resolver): static;

    /**
     * Returns the query logger, resolved first when a resolver is set.
     *
     * @return QueryLoggerInterface|null The query logger, or null when the queries are not logged
     */
    public function getQueryLogger(): ?QueryLoggerInterface;
}