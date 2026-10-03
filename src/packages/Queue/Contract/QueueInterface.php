<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Contract;

use NeoPHP\Package\Queue\Envelope;

interface QueueInterface extends MessageBusInterface
{
    public function handle(Envelope $envelope): void;

    public function transport(?string $name = null): TransportInterface;

    public function hasTransport(string $name): bool;

    public function getTransportNames(): array;

    public function getDefaultTransportName(): string;
}