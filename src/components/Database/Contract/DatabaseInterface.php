<?php

declare(strict_types=1);

namespace NeoPHP\Component\Database\Contract;

use Closure;

interface DatabaseInterface
{
    public function connection(?string $name = null): ConnectionInterface;

    public function hasConnection(string $name): bool;

    public function addConnection(string $name, array|string $config): static;

    public function getConnectionNames(): array;

    public function getDefaultConnectionName(): string;

    public function getConnections(): array;

    public function getParams(?string $name = null): array;

    public function getDriver(string $name): DriverInterface;

    public function addDriver(string $name, DriverInterface|string $driver): static;

    public function close(?string $name = null): void;

    public function setQueryLogger(?QueryLoggerInterface $logger): static;

    public function setQueryLoggerResolver(?Closure $resolver): static;

    public function getQueryLogger(): ?QueryLoggerInterface;
}