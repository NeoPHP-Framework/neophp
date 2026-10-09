<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Transport;

use NeoPHP\Component\Database\Contract\ConnectionInterface;
use NeoPHP\Package\Queue\Contract\AbstractTransport;
use NeoPHP\Package\Queue\Contract\SetupableTransportInterface;
use NeoPHP\Package\Queue\Exception\TransportException;
use NeoPHP\Package\Queue\Message\Envelope;
use NeoPHP\Package\Queue\Serializer\MessageSerializer;
use Throwable;

class DatabaseTransport extends AbstractTransport implements SetupableTransportInterface
{
    public const TABLE = 'neo_queue_jobs';

    public const FAILED_TABLE = 'neo_queue_failed';

    protected bool $ready = false;

    public function __construct(string $name, MessageSerializer $serializer, protected ConnectionInterface $connection, array $options = [])
    {
        parent::__construct($name, $serializer, $options);
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    public function getTable(): string
    {
        return (string) ($this->options['table'] ?? self::TABLE);
    }

    public function getFailedTable(): string
    {
        return (string) ($this->options['failed_table'] ?? self::FAILED_TABLE);
    }

    public function setup(): void
    {
        $driver = $this->connection->getDriver()->getName();
        $table = $this->connection->quoteIdentifier($this->getTable());
        $failed = $this->connection->quoteIdentifier($this->getFailedTable());
        [$id, $text] = match ($driver) {
            'mysql' => ['BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', 'LONGTEXT'],
            'pgsql' => ['BIGSERIAL PRIMARY KEY', 'TEXT'],
            default => ['INTEGER PRIMARY KEY AUTOINCREMENT', 'TEXT'],
        };
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $index = $driver === 'mysql' ? sprintf(', INDEX %s (queue, reserved_at, available_at)', $this->connection->quoteIdentifier($this->getTable() . '_queue_idx')) : '';

        $this->connection->executeStatement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (id %s, queue VARCHAR(190) NOT NULL, message_class VARCHAR(255) NOT NULL, body %s NOT NULL, attempts INT NOT NULL DEFAULT 0, max_attempts INT NOT NULL DEFAULT 3, priority INT NOT NULL DEFAULT 0, available_at BIGINT NOT NULL, reserved_at BIGINT NULL, created_at BIGINT NOT NULL, last_error %s NULL%s)%s',
            $table,
            $id,
            $text,
            $text,
            $index,
            $suffix,
        ));
        $this->connection->executeStatement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (id %s, queue VARCHAR(190) NOT NULL, message_class VARCHAR(255) NOT NULL, body %s NOT NULL, attempts INT NOT NULL DEFAULT 0, max_attempts INT NOT NULL DEFAULT 3, priority INT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL, failed_at BIGINT NOT NULL, last_error %s NULL)%s',
            $failed,
            $id,
            $text,
            $text,
            $suffix,
        ));

        if ($driver !== 'mysql') {
            $this->connection->executeStatement(sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s (queue, reserved_at, available_at)',
                $this->connection->quoteIdentifier($this->getTable() . '_queue_idx'),
                $table,
            ));
        }

        $this->ready = true;
    }

    public function send(Envelope $envelope): Envelope
    {
        $this->prepare();
        $record = $this->toRecord($envelope);
        unset($record['id'], $record['failed_at'], $record['class']);
        $record['message_class'] = $envelope->getMessageClass();
        $record['reserved_at'] = null;

        $this->connection->insert($this->getTable(), $record);

        return $envelope->setId($this->connection->lastInsertId());
    }

    public function get(array $queues = [], int $limit = 1): array
    {
        $this->prepare();
        $now = time();
        $table = $this->connection->quoteIdentifier($this->getTable());

        $this->connection->executeStatement(
            sprintf('UPDATE %s SET reserved_at = NULL WHERE reserved_at IS NOT NULL AND reserved_at <= ?', $table),
            [$now - $this->getRetryAfter()],
        );

        $envelopes = [];

        foreach ($this->queues($queues) as $queue) {
            $candidates = $this->connection->fetchAllAssociative(
                sprintf('SELECT * FROM %s WHERE queue = ? AND reserved_at IS NULL AND available_at <= ? ORDER BY priority DESC, available_at ASC, id ASC LIMIT %d', $table, max(1, $limit) * 3),
                [$queue, $now],
            );

            foreach ($candidates as $row) {
                $claimed = $this->connection->executeStatement(
                    sprintf('UPDATE %s SET reserved_at = ?, attempts = attempts + 1 WHERE id = ? AND reserved_at IS NULL', $table),
                    [$now, $row['id']],
                );

                if ($claimed !== 1) {
                    continue;
                }

                $row['reserved_at'] = $now;
                $row['attempts'] = (int) $row['attempts'] + 1;
                $envelopes[] = $this->fromRecord($row);

                if (count($envelopes) >= $limit) {
                    return $envelopes;
                }
            }
        }

        return $envelopes;
    }

    public function ack(Envelope $envelope): void
    {
        $this->connection->delete($this->getTable(), ['id' => $envelope->getId()]);
    }

    public function release(Envelope $envelope, int $delay = 0, ?string $error = null): void
    {
        $this->connection->update($this->getTable(), [
            'reserved_at' => null,
            'available_at' => time() + max(0, $delay),
            'last_error' => $error !== null ? $this->excerpt($error) : $envelope->getLastError(),
        ], ['id' => $envelope->getId()]);
    }

    public function reject(Envelope $envelope, string $error): void
    {
        $this->prepare();
        $record = $this->toRecord($envelope);

        $this->connection->transactional(function () use ($envelope, $record, $error): void {
            $this->connection->insert($this->getFailedTable(), [
                'queue' => $record['queue'],
                'message_class' => $record['class'],
                'body' => $record['body'],
                'attempts' => $record['attempts'],
                'max_attempts' => $record['max_attempts'],
                'priority' => $record['priority'],
                'created_at' => $record['created_at'],
                'failed_at' => time(),
                'last_error' => $this->excerpt($error),
            ]);

            if ($envelope->getId() !== null && !str_starts_with($envelope->getId(), 'sync-')) {
                $this->connection->delete($this->getTable(), ['id' => $envelope->getId()]);
            }
        });
    }

    public function count(?string $queue = null): array
    {
        $this->prepare();
        $now = time();
        $where = $queue !== null ? ' AND queue = ?' : '';
        $params = $queue !== null ? [$queue] : [];
        $table = $this->connection->quoteIdentifier($this->getTable());

        return [
            'ready' => (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE reserved_at IS NULL AND available_at <= ?%s', $table, $where), [$now, ...$params]),
            'delayed' => (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE reserved_at IS NULL AND available_at > ?%s', $table, $where), [$now, ...$params]),
            'reserved' => (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE reserved_at IS NOT NULL%s', $table, $where), $params),
            'failed' => (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE 1 = 1%s', $this->connection->quoteIdentifier($this->getFailedTable()), $where), $params),
        ];
    }

    public function getQueues(): array
    {
        $this->prepare();
        $queues = [
            ...$this->connection->fetchFirstColumn(sprintf('SELECT DISTINCT queue FROM %s', $this->connection->quoteIdentifier($this->getTable()))),
            ...$this->connection->fetchFirstColumn(sprintf('SELECT DISTINCT queue FROM %s', $this->connection->quoteIdentifier($this->getFailedTable()))),
        ];
        $queues = array_values(array_unique(array_map('strval', $queues)));
        sort($queues);

        return $queues;
    }

    public function purge(?string $queue = null): int
    {
        $this->prepare();

        if ($queue === null) {
            return $this->connection->executeStatement(sprintf('DELETE FROM %s', $this->connection->quoteIdentifier($this->getTable())));
        }

        return $this->connection->delete($this->getTable(), ['queue' => $queue]);
    }

    public function getFailed(int $limit = 50): array
    {
        $this->prepare();
        $rows = $this->connection->fetchAllAssociative(sprintf('SELECT * FROM %s ORDER BY failed_at DESC, id DESC LIMIT %d', $this->connection->quoteIdentifier($this->getFailedTable()), max(1, $limit)));

        return array_map(fn (array $row): Envelope => $this->fromRecord($row, false), $rows);
    }

    public function retryFailed(string $id): bool
    {
        $this->prepare();
        $row = $this->connection->fetchAssociative(sprintf('SELECT * FROM %s WHERE id = ?', $this->connection->quoteIdentifier($this->getFailedTable())), [$id]);

        if ($row === null) {
            return false;
        }

        $this->connection->transactional(function () use ($row, $id): void {
            $now = time();
            $this->connection->insert($this->getTable(), [
                'queue' => (string) $row['queue'],
                'message_class' => (string) $row['message_class'],
                'body' => (string) $row['body'],
                'attempts' => 0,
                'max_attempts' => (int) $row['max_attempts'],
                'priority' => (int) $row['priority'],
                'available_at' => $now,
                'reserved_at' => null,
                'created_at' => $now,
                'last_error' => $row['last_error'],
            ]);
            $this->connection->delete($this->getFailedTable(), ['id' => $id]);
        });

        return true;
    }

    public function forgetFailed(string $id): bool
    {
        $this->prepare();

        return $this->connection->delete($this->getFailedTable(), ['id' => $id]) > 0;
    }

    public function flushFailed(): int
    {
        $this->prepare();

        return $this->connection->executeStatement(sprintf('DELETE FROM %s', $this->connection->quoteIdentifier($this->getFailedTable())));
    }

    protected function prepare(): void
    {
        if ($this->ready) {
            return;
        }

        if (filter_var($this->options['auto_setup'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            try {
                $this->setup();
            } catch (Throwable $exception) {
                throw new TransportException('Unable to create the queue tables on the connection "{connection}": {error}', 0, $exception, [
                    'connection' => $this->connection->getName(),
                    'error' => $exception->getMessage(),
                ]);
            }

            return;
        }

        $this->ready = true;
    }
}