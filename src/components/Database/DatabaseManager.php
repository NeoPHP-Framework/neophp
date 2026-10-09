<?php

declare(strict_types=1);

namespace NeoPHP\Component\Database;

use Closure;
use NeoPHP\Component\Database\Connection\Connection;
use NeoPHP\Component\Database\Connection\UrlParser;
use NeoPHP\Component\Database\Contract\ConnectionInterface;
use NeoPHP\Component\Database\Contract\DriverInterface;
use NeoPHP\Component\Database\Contract\QueryLoggerInterface;
use NeoPHP\Component\Database\Driver\MysqlDriver;
use NeoPHP\Component\Database\Driver\PgsqlDriver;
use NeoPHP\Component\Database\Driver\SqliteDriver;
use NeoPHP\Component\Database\Exception\DatabaseException;
use NeoPHP\Component\Database\Provider\DatabaseProvider;
use NeoPHP\Component\Kernel\Attribute\Component;

#[Component(provider: DatabaseProvider::class)]
final class DatabaseManager implements DatabaseManagerInterface
{
    public const DEFAULT_CONNECTION = 'default';

    public const DRIVERS = [
        'mysql' => MysqlDriver::class,
        'pgsql' => PgsqlDriver::class,
        'sqlite' => SqliteDriver::class,
    ];

    protected array $configurations = [];

    protected string $default = self::DEFAULT_CONNECTION;

    protected string $basePath = '';

    protected array $connections = [];

    protected array $drivers = [];

    protected ?UrlParser $urlParser = null;

    protected ?QueryLoggerInterface $queryLogger = null;

    protected ?Closure $queryLoggerResolver = null;

    public function __construct(array $connections = [], ?string $default = null, string $basePath = '')
    {
        foreach ($connections as $name => $config) {
            $this->addConnection((string) $name, is_array($config) || is_string($config) ? $config : []);
        }

        $this->default = $default ?? (isset($this->configurations[self::DEFAULT_CONNECTION]) || $this->configurations === [] ? self::DEFAULT_CONNECTION : (string) array_key_first($this->configurations));
        $this->basePath = rtrim($basePath, '/\\');
    }

    public function setQueryLogger(?QueryLoggerInterface $logger): static
    {
        $this->queryLogger = $logger;
        $this->queryLoggerResolver = null;

        foreach ($this->connections as $connection) {
            $connection->setQueryLogger($logger);
        }

        return $this;
    }

    public function setQueryLoggerResolver(?Closure $resolver): static
    {
        $this->queryLoggerResolver = $resolver;

        return $this;
    }

    public function getQueryLogger(): ?QueryLoggerInterface
    {
        if ($this->queryLoggerResolver !== null) {
            $resolver = $this->queryLoggerResolver;
            $this->queryLoggerResolver = null;
            $this->queryLogger = $resolver();
        }

        return $this->queryLogger;
    }

    public function connection(?string $name = null): ConnectionInterface
    {
        $name ??= $this->default;

        return $this->connections[$name] ??= $this->createConnection($name);
    }

    public function hasConnection(string $name): bool
    {
        return isset($this->configurations[$name]);
    }

    public function addConnection(string $name, array|string $config): static
    {
        $this->configurations[$name] = is_string($config) ? ['url' => $config] : $config;
        unset($this->connections[$name]);

        return $this;
    }

    public function getConnectionNames(): array
    {
        return array_keys($this->configurations);
    }

    public function getDefaultConnectionName(): string
    {
        return $this->default;
    }

    public function getConnections(): array
    {
        return $this->connections;
    }

    public function getParams(?string $name = null): array
    {
        $name ??= $this->default;

        if (!$this->hasConnection($name)) {
            throw new DatabaseException($this->configurations === []
                ? 'No database connection is configured: add one in config/framework/database.yaml (DATABASE_URL in .env).'
                : 'The database connection "{name}" does not exist. Available connections: {connections}.', 0, null, [
                'name' => $name,
                'connections' => implode(', ', $this->getConnectionNames()),
            ]);
        }

        return $this->resolveParams($this->configurations[$name], $name);
    }

    public function getDriver(string $name): DriverInterface
    {
        $name = $this->normalizeDriverName($name);

        if (isset($this->drivers[$name]) && $this->drivers[$name] instanceof DriverInterface) {
            return $this->drivers[$name];
        }

        $class = $this->drivers[$name] ?? static::DRIVERS[$name] ?? null;

        if ($class === null) {
            throw new DatabaseException('The database driver "{driver}" is not supported. Supported drivers: {drivers}.', 0, null, [
                'driver' => $name,
                'drivers' => implode(', ', array_keys($this->drivers + static::DRIVERS)),
            ]);
        }

        $driver = new $class();

        if (!$driver instanceof DriverInterface) {
            throw new DatabaseException('The database driver "{class}" must implement {interface}.', 0, null, [
                'class' => $class,
                'interface' => DriverInterface::class,
            ]);
        }

        return $this->drivers[$name] = $driver;
    }

    public function addDriver(string $name, DriverInterface|string $driver): static
    {
        $this->drivers[strtolower($name)] = $driver;

        return $this;
    }

    public function close(?string $name = null): void
    {
        foreach ($this->connections as $connectionName => $connection) {
            if ($name === null || $name === $connectionName) {
                $connection->close();
            }
        }
    }

    protected function createConnection(string $name): ConnectionInterface
    {
        $params = $this->getParams($name);

        $connection = new Connection($name, $this->getDriver((string) $params['driver']), $params);
        $logger = $this->getQueryLogger();

        return $logger === null ? $connection : $connection->setQueryLogger($logger);
    }

    protected function resolveParams(array $config, string $name): array
    {
        $params = [];
        $url = $config['url'] ?? null;
        unset($config['url']);

        if (is_string($url) && trim($url) !== '') {
            $params = ($this->urlParser ??= new UrlParser())->parse($url);
        }

        foreach ($config as $key => $value) {
            if ($value !== null) {
                $params[$key] = $value;
            }
        }

        if (empty($params['driver'])) {
            throw new DatabaseException('The database connection "{name}" has no "url" nor "driver".', 0, null, ['name' => $name]);
        }

        $params['driver'] = $this->normalizeDriverName((string) $params['driver']);

        if (isset($params['port'])) {
            $params['port'] = (int) $params['port'];
        }

        if (isset($params['path']) && is_string($params['path']) && $params['path'] !== SqliteDriver::MEMORY && !$this->isAbsolutePath($params['path']) && $this->basePath !== '') {
            $params['path'] = $this->basePath . DIRECTORY_SEPARATOR . $params['path'];
        }

        return $params;
    }

    protected function normalizeDriverName(string $name): string
    {
        $name = strtolower($name);
        $name = str_starts_with($name, 'pdo_') ? substr($name, 4) : $name;

        return UrlParser::SCHEMES[$name] ?? $name;
    }

    protected function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;
    }
}