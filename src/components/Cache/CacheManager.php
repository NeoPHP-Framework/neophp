<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cache;

use Closure;
use DateInterval;
use NeoPHP\Component\Cache\Adapter\ApcuAdapter;
use NeoPHP\Component\Cache\Adapter\ArrayAdapter;
use NeoPHP\Component\Cache\Adapter\DatabaseAdapter;
use NeoPHP\Component\Cache\Adapter\FilesystemAdapter;
use NeoPHP\Component\Cache\Contract\AbstractCache;
use NeoPHP\Component\Cache\Contract\AdapterInterface;
use NeoPHP\Component\Cache\Contract\CacheInterface;
use NeoPHP\Component\Cache\Contract\CacheManagerInterface;
use NeoPHP\Component\Cache\Exception\CacheException;
use NeoPHP\Component\Database\Contract\ConnectionInterface;

class CacheManager implements CacheManagerInterface
{
    public const DEFAULT_POOL = 'app';

    public const ADAPTERS = ['filesystem', 'apcu', 'database', 'array'];

    public const DEFAULT_CONFIG = [
        'default_pool' => self::DEFAULT_POOL,
        'pools' => [
            self::DEFAULT_POOL => ['adapter' => 'filesystem', 'default_ttl' => 3600],
        ],
    ];

    protected array $pools = [];

    protected array $instances = [];

    protected string $default;

    public function __construct(array $config = [], protected string $rootPath = '', protected ?Closure $connections = null, protected ?string $secret = null)
    {
        $pools = (array) ($config['pools'] ?? []);

        if ($pools === []) {
            $pools = self::DEFAULT_CONFIG['pools'];
        }

        foreach ($pools as $name => $pool) {
            $this->pools[(string) $name] = is_string($pool) ? ['adapter' => $pool] : (array) $pool;
        }

        $default = (string) ($config['default_pool'] ?? '');
        $this->default = $default !== '' ? $default : (isset($this->pools[self::DEFAULT_POOL]) ? self::DEFAULT_POOL : (string) array_key_first($this->pools));
        $this->rootPath = rtrim($rootPath !== '' ? $rootPath : (string) getcwd(), '/\\');
    }

    public function pool(?string $name = null): CacheInterface
    {
        $name = $name === null || $name === '' ? $this->default : $name;

        return $this->instances[$name] ??= $this->createPool($name, $this->getPoolConfig($name));
    }

    public function hasPool(string $name): bool
    {
        return isset($this->pools[$name]);
    }

    public function getPoolNames(): array
    {
        return array_keys($this->pools);
    }

    public function getDefaultPool(): string
    {
        return $this->default;
    }

    public function getPoolConfig(string $name): array
    {
        if (!$this->hasPool($name)) {
            throw new CacheException('The cache pool "{name}" is not configured. Available pools: {pools}.', 0, null, [
                'name' => $name,
                'pools' => implode(', ', $this->getPoolNames()) ?: 'none',
            ]);
        }

        $config = $this->pools[$name];

        return [
                'adapter' => strtolower((string) ($config['adapter'] ?? 'filesystem')),
                'default_ttl' => isset($config['default_ttl']) && $config['default_ttl'] !== '' ? (int) $config['default_ttl'] : null,
                'namespace' => (string) ($config['namespace'] ?? $name),
                'lock_timeout' => (float) ($config['lock_timeout'] ?? AbstractCache::DEFAULT_LOCK_TIMEOUT),
            ] + $config;
    }

    public function getName(): string
    {
        return $this->pool()->getName();
    }

    public function getAdapter(): AdapterInterface
    {
        return $this->pool()->getAdapter();
    }

    public function get(string $key, ?callable $callback = null, int|DateInterval|null $ttl = null, array $tags = []): mixed
    {
        return $this->pool()->get($key, $callback, $ttl, $tags);
    }

    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null, array $tags = []): bool
    {
        return $this->pool()->set($key, $value, $ttl, $tags);
    }

    public function has(string $key): bool
    {
        return $this->pool()->has($key);
    }

    public function delete(string $key): bool
    {
        return $this->pool()->delete($key);
    }

    public function getMany(array $keys, mixed $default = null): array
    {
        return $this->pool()->getMany($keys, $default);
    }

    public function setMany(array $values, int|DateInterval|null $ttl = null, array $tags = []): bool
    {
        return $this->pool()->setMany($values, $ttl, $tags);
    }

    public function deleteMany(array $keys): bool
    {
        return $this->pool()->deleteMany($keys);
    }

    public function clear(): bool
    {
        return $this->pool()->clear();
    }

    public function invalidateTags(array $tags): bool
    {
        return $this->pool()->invalidateTags($tags);
    }

    public function prune(): int
    {
        return $this->pool()->prune();
    }

    protected function createPool(string $name, array $config): CacheInterface
    {
        return new CachePool($name, $this->createAdapter($name, $config), $config['default_ttl'], $config['lock_timeout'], $this->secret);
    }

    protected function createAdapter(string $name, array $config): AdapterInterface
    {
        $namespace = $config['namespace'];

        return match ($config['adapter']) {
            'filesystem' => new FilesystemAdapter($this->directory($name, $config), $namespace),
            'apcu' => new ApcuAdapter($namespace),
            'database' => new DatabaseAdapter($this->connection($name, $config), $namespace, (string) ($config['table'] ?? DatabaseAdapter::DEFAULT_TABLE)),
            'array' => new ArrayAdapter($namespace),
            default => throw new CacheException('The adapter "{adapter}" of the cache pool "{name}" is not supported. Available adapters: {adapters}.', 0, null, [
                'adapter' => $config['adapter'],
                'name' => $name,
                'adapters' => implode(', ', self::ADAPTERS),
            ]),
        };
    }

    protected function directory(string $name, array $config): string
    {
        $directory = (string) ($config['directory'] ?? '');

        if ($directory === '') {
            return $this->rootPath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'pools' . DIRECTORY_SEPARATOR . $name;
        }

        $directory = str_replace('%kernel.root_path%', $this->rootPath, $directory);

        return preg_match('#^([a-zA-Z]:)?[/\\\\]#', $directory) === 1 ? $directory : $this->rootPath . DIRECTORY_SEPARATOR . $directory;
    }

    protected function connection(string $name, array $config): ConnectionInterface
    {
        if ($this->connections === null) {
            throw new CacheException('The cache pool "{name}" uses the "database" adapter, but the Database component is not available.', 0, null, ['name' => $name]);
        }

        $connection = isset($config['connection']) && $config['connection'] !== '' ? (string) $config['connection'] : null;

        return ($this->connections)($connection);
    }
}