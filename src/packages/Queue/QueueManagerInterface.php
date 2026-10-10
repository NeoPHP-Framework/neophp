<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue;

use NeoPHP\Package\Queue\Contract\MessageBusInterface;
use NeoPHP\Package\Queue\Contract\TransportInterface;
use NeoPHP\Package\Queue\Exception\ConfigurationException;
use NeoPHP\Package\Queue\Exception\NoHandlerException;
use NeoPHP\Package\Queue\Exception\TransportException;
use NeoPHP\Package\Queue\Message\Envelope;

interface QueueManagerInterface extends MessageBusInterface
{
    /**
     * Calls the handlers of the message of an envelope, or the handle() method of a job; used by the workers.
     *
     * @param Envelope $envelope The envelope received from a transport
     * @return void
     * @throws ConfigurationException When the envelope has no message, a handler method does not exist or a job has no handle() method
     * @throws NoHandlerException When no handler handles the message
     */
    public function handle(Envelope $envelope): void;

    /**
     * Returns a transport, created on first use from its DSN.
     *
     * @param string|null $name Name of the transport, the default transport when null
     * @return TransportInterface The transport
     * @throws ConfigurationException When the transport is not configured, its DSN or scheme is invalid, APP_SECRET is missing or ext-redis is required
     * @throws TransportException When the storage of the transport cannot be prepared
     */
    public function transport(?string $name = null): TransportInterface;

    /**
     * Tells whether a transport is configured.
     *
     * @param string $name Name of the transport
     * @return bool True when the transport is configured
     */
    public function hasTransport(string $name): bool;

    /**
     * Returns the names of the configured transports.
     *
     * @return list<string> The names of the transports
     */
    public function getTransportNames(): array;

    /**
     * Returns the name of the transport of the unrouted messages.
     *
     * @return string The name of the default transport
     */
    public function getDefaultTransportName(): string;
}