<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Contract;

interface TransportFactoryInterface
{
    public function supports(string $dsn): bool;

    public function create(string $name, string $dsn, array $options = []): TransportInterface;
}