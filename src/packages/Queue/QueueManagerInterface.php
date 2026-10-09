<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue;

use NeoPHP\Package\Queue\Contract\MessageBusInterface;
use NeoPHP\Package\Queue\Contract\TransportInterface;
use NeoPHP\Package\Queue\Message\Envelope;

interface QueueManagerInterface extends MessageBusInterface
{
    public function handle(Envelope $envelope): void;

    public function transport(?string $name = null): TransportInterface;

    public function hasTransport(string $name): bool;

    public function getTransportNames(): array;

    public function getDefaultTransportName(): string;
}