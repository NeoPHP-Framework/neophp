<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Transport;

use NeoPHP\Component\Database\Contract\DatabaseInterface;
use NeoPHP\Package\Queue\Contract\TransportFactoryInterface;
use NeoPHP\Package\Queue\Contract\TransportInterface;
use NeoPHP\Package\Queue\Exception\ConfigurationException;
use NeoPHP\Package\Queue\Handler\HandlerInvoker;
use NeoPHP\Package\Queue\Serializer\MessageSerializer;

class TransportFactory
{
    public const SCHEMES = ['sync', 'database', 'filesystem', 'redis'];

    protected array $factories = [];

    public function __construct(
        protected MessageSerializer $serializer,
        protected HandlerInvoker $invoker,
        protected ?DatabaseInterface $database = null,
        protected string $rootPath = '',
        protected array $defaults = [],
        iterable $factories = [],
    ) {
        foreach ($factories as $factory) {
            $this->addFactory($factory);
        }
    }

    public function addFactory(TransportFactoryInterface $factory): static
    {
        $this->factories[] = $factory;

        return $this;
    }

    public function create(string $name, string $dsn, array $options = []): TransportInterface
    {
        foreach ($this->factories as $factory) {
            if ($factory->supports($dsn)) {
                return $factory->create($name, $dsn, [...$this->defaults, ...$options]);
            }
        }

        if (preg_match('#^([a-z][a-z0-9+.-]*)://(.*)$#i', trim($dsn), $matches) !== 1) {
            throw new ConfigurationException('The DSN "{dsn}" of the queue transport "{name}" is not valid (expected "scheme://...", schemes: {schemes}).', 0, null, [
                'dsn' => $dsn,
                'name' => $name,
                'schemes' => implode(', ', self::SCHEMES),
            ]);
        }

        $scheme = strtolower($matches[1]);
        [$target, $query] = array_pad(explode('?', $matches[2], 2), 2, '');
        parse_str($query, $parameters);
        $options = [...$this->defaults, ...$parameters, ...$options];

        return match ($scheme) {
            'sync' => new SyncTransport($name, $this->serializer, $this->invoker, $options),
            'database', 'doctrine' => $this->database($name, $target, $options),
            'filesystem', 'file' => new FilesystemTransport($name, $this->serializer, $this->absolute(rawurldecode($target === '' ? 'var/queue' : $target)), $options),
            'redis', 'rediss' => $this->redis($name, $matches[2], $options),
            default => throw new ConfigurationException('The queue transport scheme "{scheme}" is not supported (supported: {schemes}, or register a factory in "transports_factories").', 0, null, [
                'scheme' => $scheme,
                'schemes' => implode(', ', self::SCHEMES),
            ]),
        };
    }

    protected function database(string $name, string $target, array $options): DatabaseTransport
    {
        if ($this->database === null) {
            throw new ConfigurationException('The queue transport "{name}" uses the database but the Database component is not available.', 0, null, ['name' => $name]);
        }

        $connection = trim($target, '/');

        return new DatabaseTransport($name, $this->serializer, $this->database->connection($connection === '' || $connection === 'default' ? null : $connection), $options);
    }

    protected function redis(string $name, string $target, array $options): RedisTransport
    {
        if (!RedisTransport::isAvailable()) {
            throw new ConfigurationException('The queue transport "{name}" uses redis: install and enable the PHP extension "redis" (ext-redis).', 0, null, ['name' => $name]);
        }

        $parts = parse_url('redis://' . explode('?', $target, 2)[0]);

        if ($parts === false) {
            throw new ConfigurationException('The redis DSN of the queue transport "{name}" is not valid.', 0, null, ['name' => $name]);
        }

        return new RedisTransport($name, $this->serializer, [
            'host' => (string) ($parts['host'] ?? '127.0.0.1'),
            'port' => (int) ($parts['port'] ?? 6379),
            'password' => rawurldecode((string) ($parts['pass'] ?? $parts['user'] ?? '')),
            'database' => (int) trim((string) ($parts['path'] ?? '0'), '/'),
            'timeout' => (float) ($options['timeout'] ?? 2.0),
        ], $options);
    }

    protected function absolute(string $path): string
    {
        if ($this->rootPath === '' || preg_match('#^([A-Za-z]:)?[/\\\\]#', $path) === 1) {
            return $path;
        }

        return rtrim($this->rootPath, '/\\') . DIRECTORY_SEPARATOR . $path;
    }
}