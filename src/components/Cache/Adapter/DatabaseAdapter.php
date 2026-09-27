<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cache\Adapter;

use NeoPHP\Component\Cache\Contract\AbstractAdapter;
use NeoPHP\Component\Cache\Exception\CacheException;
use NeoPHP\Component\Database\Contract\ConnectionInterface;
use Throwable;

class DatabaseAdapter extends AbstractAdapter
{
    public const DEFAULT_TABLE = 'cache_items';

    public const LOCK_TTL = 30;

    protected bool $ready = false;

    public function __construct(protected ConnectionInterface $connection, string $namespace = '', protected string $table = self::DEFAULT_TABLE)
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new CacheException('The cache table name "{table}" is not valid.', 0, null, ['table' => $table]);
        }

        $this->namespace = $namespace;
    }

    public function getName(): string
    {
        return 'database';
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function createTable(): void
    {
        $value = match ($this->driver()) {
            'mysql' => 'LONGTEXT',
            default => 'TEXT',
        };

        $this->connection->executeStatement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (item_key VARCHAR(255) NOT NULL PRIMARY KEY, item_value %s NOT NULL, expires_at INTEGER NULL)',
            $this->connection->quoteIdentifier($this->table),
            $value,
        ));

        $this->ready = true;
    }

    public function fetch(string $id): ?string
    {
        $row = $this->run(fn (): ?array => $this->connection->fetchAssociative(
            sprintf('SELECT item_value, expires_at FROM %s WHERE item_key = ?', $this->quotedTable()),
            [$this->key($id)],
        ));

        if ($row === null) {
            return null;
        }

        $expiresAt = $row['expires_at'] === null ? null : (int) $row['expires_at'];

        if ($this->isExpired($expiresAt)) {
            $this->remove($id);

            return null;
        }

        $data = base64_decode((string) $row['item_value'], true);

        return $data === false ? null : $data;
    }

    public function save(string $id, string $data, ?int $expiresAt): bool
    {
        $sql = match ($this->driver()) {
            'mysql' => 'INSERT INTO %s (item_key, item_value, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE item_value = VALUES(item_value), expires_at = VALUES(expires_at)',
            default => 'INSERT INTO %s (item_key, item_value, expires_at) VALUES (?, ?, ?) ON CONFLICT (item_key) DO UPDATE SET item_value = excluded.item_value, expires_at = excluded.expires_at',
        };

        $this->run(fn (): int => $this->connection->executeStatement(sprintf($sql, $this->quotedTable()), [$this->key($id), base64_encode($data), $expiresAt]));

        return true;
    }

    public function remove(string $id): bool
    {
        $this->run(fn (): int => $this->connection->executeStatement(sprintf('DELETE FROM %s WHERE item_key = ?', $this->quotedTable()), [$this->key($id)]));

        return true;
    }

    public function clear(): bool
    {
        $this->run(fn (): int => $this->connection->executeStatement(sprintf("DELETE FROM %s WHERE item_key LIKE ? ESCAPE '!'", $this->quotedTable()), [$this->pattern()]));

        return true;
    }

    public function prune(): int
    {
        return (int) $this->run(fn (): int => $this->connection->executeStatement(
            sprintf("DELETE FROM %s WHERE expires_at IS NOT NULL AND expires_at <= ? AND item_key LIKE ? ESCAPE '!'", $this->quotedTable()),
            [time(), $this->pattern()],
        ));
    }

    public function lock(string $id, float $timeout): bool
    {
        $key = $this->key('lock:' . $id);
        $table = $this->quotedTable();

        $this->ensureTable();

        return $this->waitFor(function () use ($key, $table): bool {
            try {
                $this->connection->executeStatement(sprintf('INSERT INTO %s (item_key, item_value, expires_at) VALUES (?, ?, ?)', $table), [$key, '', time() + self::LOCK_TTL]);

                return true;
            } catch (Throwable) {
                $this->connection->executeStatement(sprintf('DELETE FROM %s WHERE item_key = ? AND expires_at <= ?', $table), [$key, time()]);

                return false;
            }
        }, $timeout);
    }

    public function unlock(string $id): void
    {
        $this->remove('lock:' . $id);
    }

    protected function run(callable $query): mixed
    {
        $this->ensureTable();

        return $query();
    }

    protected function ensureTable(): void
    {
        if (!$this->ready) {
            $this->createTable();
        }
    }

    protected function driver(): string
    {
        return strtolower($this->connection->getDriver()->getName());
    }

    protected function quotedTable(): string
    {
        return $this->connection->quoteIdentifier($this->table);
    }

    protected function key(string $id): string
    {
        return $this->namespace === '' ? $id : $this->namespace . ':' . $id;
    }

    protected function pattern(): string
    {
        return $this->namespace === '' ? '%' : strtr($this->namespace . ':', ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }
}