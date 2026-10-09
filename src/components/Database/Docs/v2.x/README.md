# Database

The Database component gives PDO connections to MySQL / MariaDB, PostgreSQL and SQLite and runs SQL queries with typed parameters and nested transactions.
It is not an ORM: entities, repositories, migrations and the query builder belong to the ORM package (see the ORM documentation).

## Summary

- [Module](#module)
- [Requirements](#requirements)
- [Configuration](#configuration)
- [Queries](#queries)
- [Parameters](#parameters)
- [Transactions](#transactions)
- [Commands](#commands)
- [Errors](#errors)
- [Profiler](#profiler)
- [Changelog](#changelog)

## Module

| | |
|---|---|
| Manager | `NeoPHP\Component\Database\DatabaseManager` (`final`) |
| Interface | `NeoPHP\Component\Database\DatabaseManagerInterface` |
| Attribute | `#[Component(provider: DatabaseProvider::class)]` |
| Requires | nothing |
| Required by | Orm |

Inject `DatabaseManagerInterface` in a service, a command or a controller:

```php
use NeoPHP\Component\Database\DatabaseManagerInterface;

public function __construct(private DatabaseManagerInterface $database)
{
}
```

The module is enabled by default. To disable it in a project, add it to `config/config.php` (see the Kernel documentation):

```php
NeoPHP\Component\Database\DatabaseManager::class => false,
```

It cannot be disabled while Orm is enabled.

The public API of the module is its manager and its interface, `Contract\`, the attributes, the exceptions, the events and the classes documented below. The classes marked `@internal` are used by the framework only.

## Requirements

It requires the `pdo` extension and the driver of the database:

| Driver | Extension | URL schemes |
|---|---|---|
| MySQL / MariaDB | `pdo_mysql` | `mysql://`, `mariadb://` |
| PostgreSQL | `pdo_pgsql` | `postgresql://`, `postgres://`, `pgsql://` |
| SQLite | `pdo_sqlite` | `sqlite://` |

## Configuration

`config/framework/database.yaml`:

```yaml
default: default

connections:
  default:
    url: '%env(DATABASE_URL)%'
```

`.env`:

```dotenv
# DATABASE_URL="mysql://user:password@127.0.0.1:3306/app?charset=utf8mb4"
# DATABASE_URL="postgresql://user:password@127.0.0.1:5432/app?charset=utf8"
DATABASE_URL="sqlite:///%kernel.root_path%/var/data.db"
```

A connection is configured with a `url`, with separate parameters, or both: the parameters override the parts of the URL.

```yaml
default: default

connections:
  default:
    url: '%env(DATABASE_URL)%'
  analytics:
    driver: pgsql
    host: '%env(ANALYTICS_HOST)%'
    port: 5432
    dbname: analytics
    user: '%env(ANALYTICS_USER)%'
    password: '%env(ANALYTICS_PASSWORD)%'
    charset: utf8
  cache:
    driver: sqlite
    path: var/cache.db
    options:
      ATTR_TIMEOUT: 5
```

| Parameter | Description |
|---|---|
| `url` | `driver://user:password@host:port/dbname?charset=...`; special characters of the user and the password are URL-encoded (`@` is `%40`) |
| `driver` | `mysql` (or `mariadb`), `pgsql` (or `postgresql`), `sqlite` |
| `host`, `port`, `dbname`, `user`, `password` | server connection (MySQL and PostgreSQL) |
| `unix_socket` | socket used instead of `host` and `port` |
| `charset` | `utf8mb4` by default for MySQL, `utf8` for PostgreSQL |
| `collation` | MySQL collation used by `database:create` |
| `sslmode` | PostgreSQL SSL mode |
| `path` | SQLite file, relative to the project root or absolute; `sqlite:///:memory:` for an in-memory database |
| `foreign_keys` | SQLite: set to `false` to disable `PRAGMA foreign_keys = ON` |
| `options` | PDO attributes, by name (`ATTR_TIMEOUT`) or number |

With a URL, `sqlite:///var/data.db` is relative to the project root and `sqlite:///%kernel.root_path%/var/data.db` is absolute.

The connections are opened on the first query. PDO throws exceptions and fetches associative arrays by default.

## Queries

In a controller, `getConnection()` returns the default connection, `getConnection('analytics')` another one:

```php
#[Route('/posts/{id}', name: 'post_show')]
public function show(int $id): Response
{
    $post = $this->getConnection()->fetchAssociative('SELECT * FROM post WHERE id = :id', ['id' => $id]);

    if ($post === null) {
        throw $this->createNotFoundException('Post not found.');
    }

    return $this->render('post/show.php', ['post' => $post]);
}
```

In a service, inject `ConnectionInterface` (default connection), a named connection with `#[Autowire(service: 'database.connection.<name>')]`, or `DatabaseManagerInterface` to use all the connections:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use NeoPHP\Component\Container\Attribute\Autowire;
use NeoPHP\Component\Database\Contract\ConnectionInterface;

class PostRepository
{
    public function __construct(
        protected ConnectionInterface $connection,
        #[Autowire(service: 'database.connection.analytics')] protected ConnectionInterface $analytics,
    ) {
    }

    public function findPublished(): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM post WHERE published = ? ORDER BY id DESC', [true]);
    }
}
```

| Method | Returns |
|---|---|
| `executeQuery($sql, $params)` | a `Result` |
| `executeStatement($sql, $params)` | the number of affected rows |
| `fetchAssociative()` / `fetchNumeric()` / `fetchObject()` | the first row, or `null` |
| `fetchOne()` | the first column of the first row, or `null` |
| `fetchAllAssociative()` / `fetchAllNumeric()` / `fetchAllObjects()` | all the rows |
| `fetchFirstColumn()` | the first column of every row |
| `fetchAllKeyValue()` | `[first column => second column]` |
| `fetchAllAssociativeIndexed()` | the rows indexed by their first column |
| `iterateAssociative()` | a generator, row by row |
| `insert($table, $data)` | the number of inserted rows |
| `update($table, $data, $criteria)` | the number of updated rows |
| `delete($table, $criteria)` | the number of deleted rows |
| `lastInsertId(?$sequence)` | the last inserted id (PostgreSQL: the sequence name, `post_id_seq`) |
| `quote($value)` / `quoteIdentifier($name)` | a quoted value / identifier |
| `getPdo()` | the PDO instance |

```php
$connection->insert('post', ['title' => 'Hello', 'status' => Status::Published, 'created_at' => new DateTimeImmutable()]);
$id = $connection->lastInsertId();

$connection->update('post', ['title' => 'Hello world'], ['id' => $id]);
$connection->delete('post', ['status' => [Status::Draft, Status::Archived]]);
```

`update()` and `delete()` require criteria: a `null` criterion becomes `IS NULL`, an array becomes `IN (...)`.

The connection also provides `getName()`, `getDriver()`, `getParams()`, `getDatabase()`, `isConnected()`, `close()`, `getServerVersion()`, `inTransaction()` and `getTransactionLevel()`. `fetchObject()` and `fetchAllObjects()` accept a class as third argument (`stdClass` by default).

### Result

`executeQuery()` returns a `NeoPHP\Component\Database\Result\Result`, iterable row by row:

```php
$result = $connection->executeQuery('SELECT id, title FROM post WHERE views > ?', [100]);

foreach ($result as $row) {
    echo $row['title'];
}
```

| Method | Description |
|---|---|
| `fetchAssociative()`, `fetchNumeric()`, `fetchObject($class, $arguments)`, `fetchOne()` | next row or `null` |
| `fetchAllAssociative()`, `fetchAllNumeric()`, `fetchAllObjects($class, $arguments)`, `fetchFirstColumn()`, `fetchAllKeyValue()`, `fetchAllAssociativeIndexed()` | all the rows |
| `iterateAssociative()`, `iterateNumeric()` | generators |
| `rowCount()`, `columnCount()`, `getColumnNames()` | information |
| `free()`, `getStatement()` | closes the cursor, returns the `PDOStatement` |

### Database service

`NeoPHP\Component\Database\DatabaseManagerInterface` (implemented by `DatabaseManager`) manages the connections:

| Method | Description |
|---|---|
| `connection($name = null)` | a connection (the default one without name) |
| `hasConnection($name)`, `getConnectionNames()`, `getDefaultConnectionName()`, `getConnections()` | connections |
| `addConnection($name, $config)` | adds a connection from a URL or an array of parameters |
| `getParams($name = null)` | resolved parameters of a connection |
| `getDriver($name)`, `addDriver($name, $driver)` | drivers (`DriverInterface` object or class) |
| `close($name = null)` | closes one or all the connections |

A driver implements `Contract\DriverInterface` (`getName()`, `getExtension()`, `isAvailable()`, `getDsn()`, `connect()`, `quoteIdentifier()`, `databaseExists()`, `createDatabase()`, `dropDatabase()`), usually by extending `Contract\AbstractDriver`. The framework ships `MysqlDriver`, `PgsqlDriver` and `SqliteDriver`.

## Parameters

Parameters are positional (`?`) or named (`:name`), not both in the same query. The values are bound with their type: `int`, `bool`, `null`, `float`, backed enums (their value), `DateTimeInterface` (`Y-m-d H:i:s`), `Stringable`.

An array is expanded, for `IN` clauses:

```php
$connection->fetchAllAssociative('SELECT * FROM post WHERE id IN (:ids)', ['ids' => [1, 2, 3]]);
$connection->fetchAllAssociative('SELECT * FROM post WHERE id IN (?) AND views > ?', [[1, 2, 3], 10]);
```

An empty array becomes `NULL`, so `IN (NULL)` matches nothing.

## Transactions

```php
$connection->transactional(function (ConnectionInterface $connection): void {
    $connection->insert('order', ['reference' => 'A-001']);
    $connection->insert('order_line', ['order_id' => $connection->lastInsertId(), 'quantity' => 2]);
});
```

`transactional()` commits and returns the value of the callback, or rolls back and rethrows the exception. `beginTransaction()`, `commit()` and `rollBack()` can be called directly. Nested transactions use savepoints.

## Commands

| Command | Alias | Description |
|---|---|---|
| `database:create [-c connection] [--if-not-exists]` | `db:create` | creates the database of the connection |
| `database:drop [-c connection] [--if-exists] [--force]` | `db:drop` | drops the database; asks for a confirmation, `--force` skips it (required with `--no-interaction`) |
| `database:query <sql> [-c connection]` | `db:query` | runs a SQL query and displays the result; the query is asked when missing |

```bash
php bin/neo database:create --if-not-exists
php bin/neo db:query "SELECT * FROM post LIMIT 5"
php bin/neo database:drop --force --if-exists
```

## Errors

| Exception | Thrown when |
|---|---|
| `ConnectionException` | the connection fails, or the PDO extension of the driver is missing |
| `QueryException` | a query fails; `getSql()` and `getParams()` return the query and its parameters |
| `DatabaseException` | the configuration is invalid, a connection does not exist... (parent of the two others) |

They extend `FrameworkException` (see the Exception documentation).

## Profiler

Every connection accepts a query logger (`Contract\QueryLoggerInterface`). None is attached by default: without logger, queries run exactly as before.

```php
use NeoPHP\Component\Database\Logger\QueryLogger;

$logger = new QueryLogger();
$connection->setQueryLogger($logger);
$database->setQueryLogger($logger);

$logger->getQueries();
```

Each entry holds the SQL, the parameters, the duration in ms, the connection name, the affected rows (statements without result set), the caller (first file outside the framework) and the error message when the query failed. `BEGIN`, `COMMIT`, `ROLLBACK` and savepoints are logged with the `transaction` type. `QueryLogger` keeps at most 1000 queries and counts the others.

`DatabaseProvider` registers a `QueryLogger` singleton (`database.query_logger`) and attaches it to the connections only when the Web Profiler is enabled (`web_profiler.config` with `enabled: true`), checked when the first connection is created. When a `stopwatch` service exists, each query is also added to the timeline with the `database` category.

The `Helper/WebProfiler/DatabaseProfiler` element adds a Database item to the toolbar (number of queries, warning on duplicated queries or more than 50 queries, danger on error) and a panel: queries, time, duplicated queries (same SQL several times: possible N+1), slow queries (more than 100 ms), per-connection statistics and errors.

## Changelog

- v2.0.0 — `DatabaseManager` is the `final` entry point of the module, declared with `#[Component]`; `DatabaseManagerInterface` replaces `Contract\DatabaseInterface`; `Contract\AbstractDatabase` is merged into the manager; `Helper/Profiler` is renamed `Helper/WebProfiler`; the internal classes are marked `@internal`.
- v1.25.1 — profiler integration: `QueryLoggerInterface`, `QueryLogger`, `setQueryLogger()` on connections and on the manager, transaction logging, Stopwatch events, Database panel of the Web Profiler.
- v1.17.0 — `database:query` asks for the query when it is missing.
- v1.15.0 — commands rewritten for the new console (`db:*` aliases).
- v1.11.0 — Database component: connections by URL or parameters, several connections, query and fetch methods, `insert()` / `update()` / `delete()`, expanded array parameters, nested transactions, `getConnection()` in controllers, `database:create`, `database:drop` and `database:query` commands.