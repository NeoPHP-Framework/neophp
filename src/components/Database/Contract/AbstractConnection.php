<?php

declare(strict_types=1);

namespace NeoPHP\Component\Database\Contract;

use BackedEnum;
use DateTimeInterface;
use Generator;
use NeoPHP\Component\Database\Exception\DatabaseException;
use NeoPHP\Component\Database\Exception\QueryException;
use NeoPHP\Component\Database\Result\Result;
use PDO;
use PDOException;
use PDOStatement;
use stdClass;
use Stringable;
use Throwable;
use UnitEnum;

abstract class AbstractConnection implements ConnectionInterface
{
    public const SAVEPOINT_PREFIX = 'NEO_SAVEPOINT_';

    public const TOKENS = '/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|`[^`]*`|--[^\n]*|\/\*.*?\*\/|(?<![:\w]):([A-Za-z_]\w*)|\?/s';

    protected string $name;

    protected DriverInterface $driver;

    protected array $params = [];

    protected ?PDO $pdo = null;

    protected int $transactionLevel = 0;

    protected ?QueryLoggerInterface $queryLogger = null;

    public function setQueryLogger(?QueryLoggerInterface $logger): static
    {
        $this->queryLogger = $logger;

        return $this;
    }

    public function getQueryLogger(): ?QueryLoggerInterface
    {
        return $this->queryLogger;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDriver(): DriverInterface
    {
        return $this->driver;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function getDatabase(): ?string
    {
        $database = $this->params['path'] ?? $this->params['dbname'] ?? (!empty($this->params['memory']) ? ':memory:' : null);

        return $database === null ? null : (string) $database;
    }

    public function getPdo(): PDO
    {
        return $this->pdo ??= $this->driver->connect($this->params);
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    public function close(): void
    {
        $this->pdo = null;
        $this->transactionLevel = 0;
    }

    public function getServerVersion(): string
    {
        return (string) $this->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    public function executeQuery(string $sql, array $params = []): Result
    {
        return new Result($this->run($sql, $params));
    }

    public function executeStatement(string $sql, array $params = []): int
    {
        if ($params === []) {
            if ($this->queryLogger !== null) {
                return $this->logged($sql, [], fn (): int => $this->exec($sql), 'query', true);
            }

            return $this->exec($sql);
        }

        return $this->run($sql, $params)->rowCount();
    }

    public function fetchAssociative(string $sql, array $params = []): ?array
    {
        return $this->executeQuery($sql, $params)->fetchAssociative();
    }

    public function fetchNumeric(string $sql, array $params = []): ?array
    {
        return $this->executeQuery($sql, $params)->fetchNumeric();
    }

    public function fetchObject(string $sql, array $params = [], string $class = stdClass::class): ?object
    {
        return $this->executeQuery($sql, $params)->fetchObject($class);
    }

    public function fetchOne(string $sql, array $params = []): mixed
    {
        return $this->executeQuery($sql, $params)->fetchOne();
    }

    public function fetchAllAssociative(string $sql, array $params = []): array
    {
        return $this->executeQuery($sql, $params)->fetchAllAssociative();
    }

    public function fetchAllNumeric(string $sql, array $params = []): array
    {
        return $this->executeQuery($sql, $params)->fetchAllNumeric();
    }

    public function fetchAllObjects(string $sql, array $params = [], string $class = stdClass::class): array
    {
        return $this->executeQuery($sql, $params)->fetchAllObjects($class);
    }

    public function fetchFirstColumn(string $sql, array $params = []): array
    {
        return $this->executeQuery($sql, $params)->fetchFirstColumn();
    }

    public function fetchAllKeyValue(string $sql, array $params = []): array
    {
        return $this->executeQuery($sql, $params)->fetchAllKeyValue();
    }

    public function fetchAllAssociativeIndexed(string $sql, array $params = []): array
    {
        return $this->executeQuery($sql, $params)->fetchAllAssociativeIndexed();
    }

    public function iterateAssociative(string $sql, array $params = []): Generator
    {
        return $this->executeQuery($sql, $params)->iterateAssociative();
    }

    public function insert(string $table, array $data): int
    {
        if ($data === []) {
            throw new DatabaseException('Unable to insert into "{table}": no data given.', 0, null, ['table' => $table]);
        }

        $columns = array_map(fn (string|int $column): string => $this->quoteIdentifier((string) $column), array_keys($data));
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $this->quoteIdentifier($table), implode(', ', $columns), implode(', ', array_fill(0, count($data), '?')));

        return $this->executeStatement($sql, array_values($data));
    }

    public function update(string $table, array $data, array $criteria): int
    {
        if ($data === []) {
            throw new DatabaseException('Unable to update "{table}": no data given.', 0, null, ['table' => $table]);
        }

        $set = [];

        foreach (array_keys($data) as $column) {
            $set[] = $this->quoteIdentifier((string) $column) . ' = ?';
        }

        [$where, $params] = $this->buildCriteria($table, $criteria);

        return $this->executeStatement(sprintf('UPDATE %s SET %s WHERE %s', $this->quoteIdentifier($table), implode(', ', $set), $where), [...array_values($data), ...$params]);
    }

    public function delete(string $table, array $criteria): int
    {
        [$where, $params] = $this->buildCriteria($table, $criteria);

        return $this->executeStatement(sprintf('DELETE FROM %s WHERE %s', $this->quoteIdentifier($table), $where), $params);
    }

    public function lastInsertId(?string $sequence = null): string
    {
        return (string) $this->getPdo()->lastInsertId($sequence);
    }

    public function beginTransaction(): void
    {
        $pdo = $this->getPdo();

        if ($this->transactionLevel === 0) {
            $this->queryLogger === null ? $pdo->beginTransaction() : $this->logged('BEGIN', [], static fn (): bool => $pdo->beginTransaction(), 'transaction');
        } else {
            $this->execTransaction('SAVEPOINT ' . self::SAVEPOINT_PREFIX . $this->transactionLevel);
        }

        $this->transactionLevel++;
    }

    public function commit(): void
    {
        $this->assertTransaction();
        $this->transactionLevel--;

        if ($this->transactionLevel === 0) {
            $pdo = $this->getPdo();
            $this->queryLogger === null ? $pdo->commit() : $this->logged('COMMIT', [], static fn (): bool => $pdo->commit(), 'transaction');

            return;
        }

        $this->execTransaction('RELEASE SAVEPOINT ' . self::SAVEPOINT_PREFIX . $this->transactionLevel);
    }

    public function rollBack(): void
    {
        $this->assertTransaction();
        $this->transactionLevel--;

        if ($this->transactionLevel === 0) {
            $pdo = $this->getPdo();
            $this->queryLogger === null ? $pdo->rollBack() : $this->logged('ROLLBACK', [], static fn (): bool => $pdo->rollBack(), 'transaction');

            return;
        }

        $this->execTransaction('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT_PREFIX . $this->transactionLevel);
    }

    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0;
    }

    public function getTransactionLevel(): int
    {
        return $this->transactionLevel;
    }

    public function transactional(callable $callback): mixed
    {
        $this->beginTransaction();
        $level = $this->transactionLevel;

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->transactionLevel >= $level) {
                $this->rollBack();
            }

            throw $exception;
        }
    }

    public function quote(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        [$value, $type] = $this->normalizeValue($value);

        return $type === PDO::PARAM_INT ? (string) $value : (string) $this->getPdo()->quote((string) $value);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->driver->quoteIdentifier($identifier);
    }

    protected function run(string $sql, array $params): PDOStatement
    {
        if ($this->queryLogger !== null) {
            return $this->logged($sql, $params, fn (): PDOStatement => $this->prepareAndExecute($sql, $params));
        }

        return $this->prepareAndExecute($sql, $params);
    }

    protected function prepareAndExecute(string $sql, array $params): PDOStatement
    {
        [$query, $values] = $this->expand($sql, $params);

        try {
            $statement = $this->getPdo()->prepare($query);

            foreach ($values as $key => $value) {
                [$value, $type] = $this->normalizeValue($value);
                $statement->bindValue(is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':'), $value, $type);
            }

            $statement->execute();
        } catch (PDOException $exception) {
            throw QueryException::fromThrowable($exception, $sql, $params);
        }

        return $statement;
    }

    protected function exec(string $sql): int
    {
        try {
            return (int) $this->getPdo()->exec($sql);
        } catch (PDOException $exception) {
            throw QueryException::fromThrowable($exception, $sql);
        }
    }

    protected function execTransaction(string $sql): void
    {
        $pdo = $this->getPdo();

        if ($this->queryLogger === null) {
            $pdo->exec($sql);

            return;
        }

        $this->logged($sql, [], static fn (): int|false => $pdo->exec($sql), 'transaction');
    }

    protected function logged(string $sql, array $params, callable $operation, string $type = 'query', bool $countRows = false): mixed
    {
        $start = microtime(true);

        try {
            $result = $operation();
        } catch (Throwable $exception) {
            $this->queryLogger?->log($this->name, $sql, $params, $start, (microtime(true) - $start) * 1000, null, ($exception->getPrevious() ?? $exception)->getMessage(), $type);

            throw $exception;
        }

        $duration = (microtime(true) - $start) * 1000;
        $rows = match (true) {
            $result instanceof PDOStatement => $result->columnCount() === 0 ? $result->rowCount() : null,
            $countRows && is_int($result) => $result,
            default => null,
        };

        $this->queryLogger?->log($this->name, $sql, $params, $start, $duration, $rows, null, $type);

        return $result;
    }

    protected function expand(string $sql, array $params): array
    {
        $hasArray = false;

        foreach ($params as $value) {
            if (is_array($value)) {
                $hasArray = true;
                break;
            }
        }

        if (!$hasArray) {
            return [$sql, $params];
        }

        $named = [];

        foreach ($params as $key => $value) {
            if (is_string($key)) {
                $named[ltrim($key, ':')] = $value;
            }
        }

        $values = [];
        $position = 0;
        $query = (string) preg_replace_callback(self::TOKENS, function (array $m) use ($params, $named, &$values, &$position): string {
            if ($m[0] === '?') {
                $value = $params[$position++] ?? null;

                if (!is_array($value)) {
                    $values[] = $value;

                    return '?';
                }

                if ($value === []) {
                    return 'NULL';
                }

                array_push($values, ...array_values($value));

                return implode(', ', array_fill(0, count($value), '?'));
            }

            if (!isset($m[1]) || $m[1] === '' || !array_key_exists($m[1], $named)) {
                return $m[0];
            }

            $value = $named[$m[1]];

            if (!is_array($value)) {
                $values[$m[1]] = $value;

                return $m[0];
            }

            if ($value === []) {
                return 'NULL';
            }

            $placeholders = [];

            foreach (array_values($value) as $index => $item) {
                $values[$m[1] . '_' . $index] = $item;
                $placeholders[] = ':' . $m[1] . '_' . $index;
            }

            return implode(', ', $placeholders);
        }, $sql);

        if ($values !== [] && !array_is_list($values) && array_filter(array_keys($values), 'is_int') !== []) {
            throw new DatabaseException('Positional ("?") and named (":name") parameters cannot be mixed in the same query.');
        }

        return [$query, $values];
    }

    protected function normalizeValue(mixed $value): array
    {
        return match (true) {
            $value === null => [null, PDO::PARAM_NULL],
            is_bool($value) => [$value, PDO::PARAM_BOOL],
            is_int($value) => [$value, PDO::PARAM_INT],
            is_float($value) => [(string) $value, PDO::PARAM_STR],
            $value instanceof BackedEnum => $this->normalizeValue($value->value),
            $value instanceof UnitEnum => [$value->name, PDO::PARAM_STR],
            $value instanceof DateTimeInterface => [$value->format('Y-m-d H:i:s'), PDO::PARAM_STR],
            $value instanceof Stringable => [(string) $value, PDO::PARAM_STR],
            is_resource($value) => [$value, PDO::PARAM_LOB],
            is_scalar($value) => [(string) $value, PDO::PARAM_STR],
            default => throw new DatabaseException('Unable to bind a value of type "{type}" to a query.', 0, null, ['type' => get_debug_type($value)]),
        };
    }

    protected function buildCriteria(string $table, array $criteria): array
    {
        if ($criteria === []) {
            throw new DatabaseException('Unable to update or delete rows of "{table}" without criteria.', 0, null, ['table' => $table]);
        }

        $where = [];
        $params = [];

        foreach ($criteria as $column => $value) {
            $column = $this->quoteIdentifier((string) $column);

            if ($value === null) {
                $where[] = $column . ' IS NULL';
            } elseif (is_array($value)) {
                $where[] = $value === [] ? '1 = 0' : $column . ' IN (' . implode(', ', array_fill(0, count($value), '?')) . ')';
                array_push($params, ...array_values($value));
            } else {
                $where[] = $column . ' = ?';
                $params[] = $value;
            }
        }

        return [implode(' AND ', $where), $params];
    }

    protected function assertTransaction(): void
    {
        if ($this->transactionLevel === 0) {
            throw new DatabaseException('There is no active transaction on the "{connection}" connection.', 0, null, ['connection' => $this->name]);
        }
    }
}