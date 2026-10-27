<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Storage;

use NeoPHP\Component\Database\Contract\ConnectionInterface;
use NeoPHP\Package\Notifier\Model\Notification;
use NeoPHP\Package\Notifier\Model\StoredNotification;
use NeoPHP\Package\Notifier\WebPush\Subscription;

/**
 * @internal
 */
class NotificationStore
{
    public const TABLE = 'neo_notifications';

    public const SUBSCRIPTIONS_TABLE = 'neo_push_subscriptions';

    protected bool $ready = false;

    public function __construct(protected ConnectionInterface $connection, protected bool $autoSetup = true)
    {
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    public function setup(): void
    {
        $driver = $this->connection->getDriver()->getName();
        $notifications = $this->connection->quoteIdentifier(self::TABLE);
        $subscriptions = $this->connection->quoteIdentifier(self::SUBSCRIPTIONS_TABLE);
        [$id, $text] = match ($driver) {
            'mysql' => ['BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', 'LONGTEXT'],
            'pgsql' => ['BIGSERIAL PRIMARY KEY', 'TEXT'],
            default => ['INTEGER PRIMARY KEY AUTOINCREMENT', 'TEXT'],
        };
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $this->connection->executeStatement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (id %s, recipient VARCHAR(190) NOT NULL, subject VARCHAR(255) NOT NULL, content %s NOT NULL, url VARCHAR(2048) NULL, type VARCHAR(190) NULL, importance VARCHAR(16) NOT NULL, data %s NULL, created_at BIGINT NOT NULL, read_at BIGINT NULL)%s',
            $notifications,
            $id,
            $text,
            $text,
            $suffix,
        ));
        $this->connection->executeStatement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (id %s, recipient VARCHAR(190) NOT NULL, endpoint_hash CHAR(64) NOT NULL, endpoint %s NOT NULL, public_key VARCHAR(255) NOT NULL, auth_secret VARCHAR(64) NOT NULL, created_at BIGINT NOT NULL)%s',
            $subscriptions,
            $id,
            $text,
            $suffix,
        ));

        foreach ([
                     [self::TABLE . '_recipient_idx', $notifications, 'recipient, read_at', false],
                     [self::SUBSCRIPTIONS_TABLE . '_endpoint_idx', $subscriptions, 'endpoint_hash', true],
                     [self::SUBSCRIPTIONS_TABLE . '_recipient_idx', $subscriptions, 'recipient', false],
                 ] as [$name, $table, $columns, $unique]) {
            $this->createIndex($driver, $name, $table, $columns, $unique);
        }

        $this->ready = true;
    }

    public function add(string $recipient, Notification $notification, ?int $now = null): int
    {
        $this->prepare();
        $this->connection->insert(self::TABLE, [
            'recipient' => $recipient,
            'subject' => mb_substr($notification->getSubject(), 0, 255),
            'content' => $notification->getContent(),
            'url' => $notification->getUrl(),
            'type' => $notification->getType(),
            'importance' => $notification->getImportance(),
            'data' => $notification->getData() === [] ? null : json_encode($notification->getData(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => $now ?? time(),
            'read_at' => null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return list<StoredNotification>
     */
    public function find(string $recipient, int $limit = 20, bool $unreadOnly = false, int $offset = 0): array
    {
        $this->prepare();
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT * FROM %s WHERE recipient = ?%s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', $this->connection->quoteIdentifier(self::TABLE), $unreadOnly ? ' AND read_at IS NULL' : '', max(1, $limit), max(0, $offset)),
            [$recipient],
        );

        return array_map(static fn (array $row): StoredNotification => StoredNotification::fromRow($row), $rows);
    }

    public function countUnread(string $recipient): int
    {
        $this->prepare();

        return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE recipient = ? AND read_at IS NULL', $this->connection->quoteIdentifier(self::TABLE)), [$recipient]);
    }

    public function markAsRead(string $recipient, ?int $id = null, ?int $now = null): int
    {
        $this->prepare();
        $sql = sprintf('UPDATE %s SET read_at = ? WHERE recipient = ? AND read_at IS NULL', $this->connection->quoteIdentifier(self::TABLE));
        $params = [$now ?? time(), $recipient];

        if ($id !== null) {
            $sql .= ' AND id = ?';
            $params[] = $id;
        }

        return $this->connection->executeStatement($sql, $params);
    }

    public function delete(string $recipient, ?int $id = null): int
    {
        $this->prepare();

        return $this->connection->delete(self::TABLE, $id === null ? ['recipient' => $recipient] : ['recipient' => $recipient, 'id' => $id]);
    }

    public function purge(int $before, bool $readOnly = false): int
    {
        $this->prepare();

        return $this->connection->executeStatement(
            sprintf('DELETE FROM %s WHERE created_at < ?%s', $this->connection->quoteIdentifier(self::TABLE), $readOnly ? ' AND read_at IS NOT NULL' : ''),
            [$before],
        );
    }

    public function saveSubscription(string $recipient, Subscription $subscription, ?int $now = null): void
    {
        $this->prepare();
        $hash = hash('sha256', $subscription->getEndpoint());
        $this->connection->delete(self::SUBSCRIPTIONS_TABLE, ['endpoint_hash' => $hash]);
        $this->connection->insert(self::SUBSCRIPTIONS_TABLE, [
            'recipient' => $recipient,
            'endpoint_hash' => $hash,
            'endpoint' => $subscription->getEndpoint(),
            'public_key' => $subscription->getPublicKey(),
            'auth_secret' => $subscription->getAuthSecret(),
            'created_at' => $now ?? time(),
        ]);
    }

    /**
     * @return list<Subscription>
     */
    public function getSubscriptions(string $recipient): array
    {
        $this->prepare();
        $rows = $this->connection->fetchAllAssociative(sprintf('SELECT endpoint, public_key, auth_secret FROM %s WHERE recipient = ? ORDER BY id', $this->connection->quoteIdentifier(self::SUBSCRIPTIONS_TABLE)), [$recipient]);

        return array_map(static fn (array $row): Subscription => new Subscription((string) $row['endpoint'], (string) $row['public_key'], (string) $row['auth_secret']), $rows);
    }

    public function deleteSubscription(string $endpoint, ?string $recipient = null): bool
    {
        $this->prepare();
        $criteria = ['endpoint_hash' => hash('sha256', $endpoint)];

        if ($recipient !== null) {
            $criteria['recipient'] = $recipient;
        }

        return $this->connection->delete(self::SUBSCRIPTIONS_TABLE, $criteria) > 0;
    }

    protected function prepare(): void
    {
        if (!$this->ready && $this->autoSetup) {
            $this->setup();
        }
    }

    protected function createIndex(string $driver, string $name, string $table, string $columns, bool $unique): void
    {
        $index = $this->connection->quoteIdentifier($name);
        $kind = $unique ? 'UNIQUE INDEX' : 'INDEX';

        if ($driver !== 'mysql') {
            $this->connection->executeStatement(sprintf('CREATE %s IF NOT EXISTS %s ON %s (%s)', $kind, $index, $table, $columns));

            return;
        }

        $exists = $this->connection->fetchOne('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [trim($table, '`'), $name]);

        if ((int) $exists === 0) {
            $this->connection->executeStatement(sprintf('CREATE %s %s ON %s (%s)', $kind, $index, $table, $columns));
        }
    }
}