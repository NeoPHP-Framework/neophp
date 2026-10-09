<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Contract;

use NeoPHP\Component\Database\Contract\ConnectionInterface;
use NeoPHP\Package\Orm\Exception\MigrationException;

abstract class AbstractMigration implements MigrationInterface
{
    public const PREFIX = 'Migration_';

    protected array $sql = [];

    public function __construct(protected ConnectionInterface $connection, protected PlatformInterface $platform)
    {
    }

    public function getVersion(): string
    {
        $class = static::class;
        $short = substr($class, (int) strrpos($class, '\\') + (str_contains($class, '\\') ? 1 : 0));

        return str_starts_with($short, self::PREFIX) ? substr($short, strlen(self::PREFIX)) : $short;
    }

    public function getDescription(): string
    {
        return '';
    }

    public function getSql(): array
    {
        return $this->sql;
    }

    public function clearSql(): void
    {
        $this->sql = [];
    }

    public function isTransactional(): bool
    {
        return true;
    }

    public function getPlatformName(): string
    {
        return $this->platform->getName();
    }

    protected function addSql(string $sql, array $params = []): void
    {
        $this->sql[] = [$sql, $params];
    }

    protected function abortIf(bool $condition, string $message = 'The migration was aborted.'): void
    {
        if ($condition) {
            throw new MigrationException('Migration {version}: {message}', 0, null, ['version' => $this->getVersion(), 'message' => $message]);
        }
    }
}