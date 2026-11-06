<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\ApiToken;

use NeoPHP\Component\Database\Contract\ConnectionInterface;
use NeoPHP\Package\Security\Contract\UserInterface;
use NeoPHP\Package\Security\Exception\SecurityException;
use NeoPHP\Package\Security\User\UserClass;

class ApiTokenManager
{
    public const DEFAULT_TABLE = 'api_tokens';

    public const PREFIX = 'neo';

    protected bool $ready = false;

    public function __construct(protected ConnectionInterface $connection, protected string $table = self::DEFAULT_TABLE)
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new SecurityException('The API token table name "{table}" is not valid.', 0, null, ['table' => $table]);
        }
    }

    public function createTable(): void
    {
        $this->connection->executeStatement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (selector VARCHAR(32) NOT NULL PRIMARY KEY, token_hash VARCHAR(64) NOT NULL, user_class VARCHAR(255) NOT NULL, identifier VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, scopes TEXT NOT NULL, created_at INTEGER NOT NULL, last_used_at INTEGER NULL, expires_at INTEGER NULL)',
            $this->quotedTable(),
        ));

        $this->ready = true;
    }

    public function create(UserInterface $user, string $name, array $scopes = [], ?int $lifetime = null): string
    {
        $this->ensureTable();
        $selector = bin2hex(random_bytes(8));
        $secret = bin2hex(random_bytes(32));

        $this->connection->insert($this->table, [
            'selector' => $selector,
            'token_hash' => hash('sha256', $secret),
            'user_class' => UserClass::of($user),
            'identifier' => $user->getUserIdentifier(),
            'name' => $name,
            'scopes' => (string) json_encode($scopes),
            'created_at' => time(),
            'last_used_at' => null,
            'expires_at' => $lifetime !== null ? time() + max(1, $lifetime) : null,
        ]);

        return self::PREFIX . '_' . $selector . '_' . $secret;
    }

    public function find(string $token): ?array
    {
        $parts = explode('_', $token);

        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            return null;
        }

        $this->ensureTable();
        $row = $this->connection->fetchAssociative(sprintf('SELECT * FROM %s WHERE selector = ?', $this->quotedTable()), [$parts[1]]);

        if ($row === null || !hash_equals((string) $row['token_hash'], hash('sha256', $parts[2]))) {
            return null;
        }

        $record = $this->hydrate($row);

        if ($record['expires_at'] !== null && $record['expires_at'] <= time()) {
            return null;
        }

        $this->connection->update($this->table, ['last_used_at' => time()], ['selector' => $record['selector']]);

        return $record;
    }

    public function findUserTokens(UserInterface $user): array
    {
        $this->ensureTable();
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT * FROM %s WHERE user_class = ? AND identifier = ? ORDER BY created_at DESC', $this->quotedTable()),
            [UserClass::of($user), $user->getUserIdentifier()],
        );

        return array_map(fn (array $row): array => $this->hydrate($row), $rows);
    }

    public function revoke(string $selector): bool
    {
        $this->ensureTable();

        return $this->connection->delete($this->table, ['selector' => $selector]) > 0;
    }

    public function revokeAll(UserInterface $user): int
    {
        $this->ensureTable();

        return $this->connection->delete($this->table, ['user_class' => UserClass::of($user), 'identifier' => $user->getUserIdentifier()]);
    }

    protected function hydrate(array $row): array
    {
        return [
            'selector' => (string) $row['selector'],
            'class' => (string) $row['user_class'],
            'identifier' => (string) $row['identifier'],
            'name' => (string) $row['name'],
            'scopes' => array_values(array_map('strval', (array) json_decode((string) $row['scopes'], true))),
            'created_at' => (int) $row['created_at'],
            'last_used_at' => isset($row['last_used_at']) ? (int) $row['last_used_at'] : null,
            'expires_at' => isset($row['expires_at']) ? (int) $row['expires_at'] : null,
        ];
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