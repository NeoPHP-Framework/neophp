<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue;

use DateTimeInterface;
use NeoPHP\Component\Event\EventManagerInterface;
use NeoPHP\Component\Kernel\Attribute\Package;
use NeoPHP\Package\Queue\Attribute\AsMessage;
use NeoPHP\Package\Queue\Contract\TransportInterface;
use NeoPHP\Package\Queue\Event\MessageDispatchedEvent;
use NeoPHP\Package\Queue\Exception\ConfigurationException;
use NeoPHP\Package\Queue\Handler\HandlerInvoker;
use NeoPHP\Package\Queue\Message\Envelope;
use NeoPHP\Package\Queue\Provider\QueueProvider;
use NeoPHP\Package\Queue\Retry\RetryStrategy;
use NeoPHP\Package\Queue\Trace\QueueTrace;
use NeoPHP\Package\Queue\Transport\SyncTransport;
use NeoPHP\Package\Queue\Transport\TransportFactory;
use ReflectionClass;
use Throwable;

#[Package(provider: QueueProvider::class)]
final class QueueManager implements QueueManagerInterface
{
    protected array $transports = [];

    protected array $attributes = [];

    public function __construct(
        protected array $dsns,
        protected TransportFactory $factory,
        protected HandlerInvoker $invoker,
        protected RetryStrategy $retry,
        protected string $defaultTransport = 'async',
        protected array $routing = [],
        protected ?EventManagerInterface $events = null,
        protected ?QueueTrace $trace = null,
    ) {
    }

    public function dispatch(object $message, array $options = []): Envelope
    {
        $attribute = $this->attribute($message::class);
        $name = (string) ($options['transport'] ?? $this->route($message) ?? $attribute->transport ?? $this->defaultTransport);
        $transport = $this->transport($name);
        $envelope = (new Envelope($message))
            ->setTransport($transport->getName())
            ->setQueue((string) ($options['queue'] ?? $attribute->queue ?? $transport->getDefaultQueue()))
            ->setPriority((int) ($options['priority'] ?? $attribute->priority ?? 0))
            ->setMaxAttempts((int) ($options['max_attempts'] ?? $attribute->maxAttempts ?? $this->retry->getMaxAttempts()));
        $delay = $options['delay'] ?? 0;
        $envelope->setAvailableAt($delay instanceof DateTimeInterface ? $delay->getTimestamp() : $envelope->getCreatedAt() + max(0, (int) $delay));

        $sync = $transport instanceof SyncTransport;
        $start = microtime(true);
        $error = null;

        try {
            $envelope = $transport->send($envelope);
        } catch (Throwable $exception) {
            $error = $exception;

            throw $exception;
        } finally {
            $this->trace?->record([
                'class' => $envelope->getMessageClass(),
                'transport' => $envelope->getTransport(),
                'queue' => $envelope->getQueue(),
                'delay' => $envelope->getDelay(),
                'priority' => $envelope->getPriority(),
                'id' => $envelope->getId(),
                'sync' => $sync,
                'duration' => round((microtime(true) - $start) * 1000, 2),
                'error' => $error?->getMessage(),
            ]);
        }

        $this->events?->dispatch(new MessageDispatchedEvent($envelope, $sync));

        return $envelope;
    }

    public function handle(Envelope $envelope): void
    {
        $message = $envelope->getMessage();

        if ($message === null) {
            throw new ConfigurationException('The envelope "{id}" has no message.', 0, null, ['id' => (string) $envelope->getId()]);
        }

        $this->invoker->invoke($message);
    }

    public function transport(?string $name = null): TransportInterface
    {
        $name ??= $this->defaultTransport;

        if (isset($this->transports[$name])) {
            return $this->transports[$name];
        }

        if (!isset($this->dsns[$name])) {
            throw new ConfigurationException('The queue transport "{name}" is not configured (available: {available}).', 0, null, [
                'name' => $name,
                'available' => implode(', ', array_keys($this->dsns)),
            ]);
        }

        $config = $this->dsns[$name];
        $config = is_array($config) ? $config : ['dsn' => (string) $config];

        return $this->transports[$name] = $this->factory->create($name, (string) ($config['dsn'] ?? ''), (array) ($config['options'] ?? []));
    }

    public function hasTransport(string $name): bool
    {
        return isset($this->dsns[$name]);
    }

    public function getTransportNames(): array
    {
        return array_map('strval', array_keys($this->dsns));
    }

    public function getDefaultTransportName(): string
    {
        return $this->defaultTransport;
    }

    public function getRetryStrategy(): RetryStrategy
    {
        return $this->retry;
    }

    public function getInvoker(): HandlerInvoker
    {
        return $this->invoker;
    }

    public function getEvents(): ?EventManagerInterface
    {
        return $this->events;
    }

    public function getDsn(string $name): string
    {
        $config = $this->dsns[$name] ?? '';

        return is_array($config) ? (string) ($config['dsn'] ?? '') : (string) $config;
    }

    protected function route(object $message): ?string
    {
        if ($this->routing === []) {
            return null;
        }

        foreach ([$message::class, ...array_values((array) class_parents($message)), ...array_values((array) class_implements($message))] as $type) {
            if (isset($this->routing[$type])) {
                return (string) $this->routing[$type];
            }
        }

        return null;
    }

    protected function attribute(string $class): ?AsMessage
    {
        if (!array_key_exists($class, $this->attributes)) {
            $attributes = class_exists($class) ? (new ReflectionClass($class))->getAttributes(AsMessage::class) : [];
            $this->attributes[$class] = $attributes === [] ? null : $attributes[0]->newInstance();
        }

        return $this->attributes[$class];
    }
}