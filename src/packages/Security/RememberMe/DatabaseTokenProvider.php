<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\RememberMe;

use NeoPHP\Component\Database\Contract\ConnectionInterface;
use NeoPHP\Package\Security\Exception\SecurityException;

class DatabaseTokenProvider implements TokenProviderInterface
{
    public const DEFAULT_TABLE = 'remember_me_tokens';

    protected bool $ready = false;

    public function __construct(protected ConnectionInterface $connection, protected string $table = self::DEFAULT_TABLE)
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new SecurityException('The remember-me table name "{table}" is not valid.', 0, null, ['table' => $table]);
        }
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function createTable(): void
    {
        $this->connection->executeStatement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (series VARCHAR(64) NOT NULL PRIMARY KEY, token_hash VARCHAR(64) NOT NULL, user_class VARCHAR(255) NOT NULL, identifier VARCHAR(255) NOT NULL, fingerprint VARCHAR(64) NOT NULL, created_at INTEGER NOT NULL, last_used_at INTEGER NOT NULL, expires_at INTEGER NOT NULL, user_agent VARCHAR(255) NULL, ip VARCHAR(45) NULL)',
            $this->quotedTable(),
        ));

        $this->ready = true;
    }

    public function createToken(PersistentToken $token): void
    {
        $this->ensureTable();
        $this->connection->executeStatement(sprintf('DELETE FROM %s WHERE expires_at <= ?', $this->quotedTable()), [time()]);
        $this->connection->insert($this->table, [
            'series' => $token->series,
            'token_hash' => $token->tokenHash,
            'user_class' => $token->class,
            'identifier' => $token->identifier,
            'fingerprint' => $token->fingerprint,
            'created_at' => $token->createdAt,
            'last_used_at' => $token->lastUsedAt,
            'expires_at' => $token->expiresAt,
            'user_agent' => $token->userAgent === null ? null : mb_substr($token->userAgent, 0, 255),
            'ip' => $token->ip,
        ]);
    }

    public function loadToken(string $series): ?PersistentToken
    {
        $this->ensureTable();
        $row = $this->connection->fetchAssociative(sprintf('SELECT * FROM %s WHERE series = ?', $this->quotedTable()), [$series]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function touchToken(string $series, int $lastUsed): void
    {
        $this->ensureTable();
        $this->connection->update($this->table, ['last_used_at' => $lastUsed], ['series' => $series]);
    }

    public function deleteToken(string $series): void
    {
        $this->ensureTable();
        $this->connection->delete($this->table, ['series' => $series]);
    }

    public function findUserTokens(string $class, string $identifier): array
    {
        $this->ensureTable();
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT * FROM %s WHERE user_class = ? AND identifier = ? AND expires_at > ? ORDER BY last_used_at DESC', $this->quotedTable()),
            [$class, $identifier, time()],
        );

        return array_map(fn (array $row): PersistentToken => $this->hydrate($row), $rows);
    }

    public function deleteUserTokens(string $class, string $identifier): int
    {
        $this->ensureTable();

        return $this->connection->delete($this->table, ['user_class' => $class, 'identifier' => $identifier]);
    }

    protected function hydrate(array $row): PersistentToken
    {
        return new PersistentToken(
            (string) $row['series'],
            (string) $row['token_hash'],
            (string) $row['user_class'],
            (string) $row['identifier'],
            (string) $row['fingerprint'],
            (int) $row['created_at'],
            (int) $row['last_used_at'],
            (int) $row['expires_at'],
            isset($row['user_agent']) ? (string) $row['user_agent'] : null,
            isset($row['ip']) ? (string) $row['ip'] : null,
        );
    }

    protected function ensureTable(): void
    {
        if (!$this->ready) {
            $this->createTable();
        }
    }

    protected function quotedTable(): string
    {
        return $this->connection->quoteIdentifier($this->table);
    }
}