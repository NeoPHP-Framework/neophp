<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Transport;

use NeoPHP\Package\Queue\Contract\AbstractTransport;
use NeoPHP\Package\Queue\Exception\ConfigurationException;
use NeoPHP\Package\Queue\Exception\TransportException;
use NeoPHP\Package\Queue\Message\Envelope;
use NeoPHP\Package\Queue\Serializer\MessageSerializer;
use Redis;
use Throwable;

class RedisTransport extends AbstractTransport
{
    public const PRIORITY_FACTOR = 10000000000;

    protected ?Redis $redis = null;

    public function __construct(string $name, MessageSerializer $serializer, protected array $connection = [], array $options = [])
    {
        parent::__construct($name, $serializer, $options);
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('redis') && class_exists(Redis::class);
    }

    public function send(Envelope $envelope): Envelope
    {
        $redis = $this->redis();
        $envelope->setId(sprintf('%013x%s', (int) (microtime(true) * 1000000), bin2hex(random_bytes(4))));
        $record = $this->toRecord($envelope);
        $payload = $this->encode($record);
        $redis->sAdd($this->key('queues'), $envelope->getQueue());

        if ($envelope->getAvailableAt() > time()) {
            $redis->zAdd($this->key('delayed', $envelope->getQueue()), $envelope->getAvailableAt(), $payload);
        } else {
            $redis->zAdd($this->key('ready', $envelope->getQueue()), $this->score($record), $payload);
        }

        return $envelope;
    }

    public function get(array $queues = [], int $limit = 1): array
    {
        $redis = $this->redis();
        $now = time();
        $envelopes = [];

        foreach ($this->queues($queues) as $queue) {
            $this->migrate($queue, $now);

            while (count($envelopes) < $limit) {
                $popped = $redis->zPopMin($this->key('ready', $queue), 1);

                if (!is_array($popped) || $popped === []) {
                    break;
                }

                $record = json_decode((string) array_key_first($popped), true);

                if (!is_array($record)) {
                    continue;
                }

                $record['attempts'] = (int) ($record['attempts'] ?? 0) + 1;
                $record['reserved_at'] = $now;
                $payload = $this->encode($record);
                $redis->zAdd($this->key('reserved', $queue), $now, $payload);
                $envelopes[] = $this->fromRecord($record)->setMeta('redis_payload', $payload);
            }

            if (count($envelopes) >= $limit) {
                break;
            }
        }

        return $envelopes;
    }

    public function ack(Envelope $envelope): void
    {
        $payload = $envelope->getMeta('redis_payload');

        if (is_string($payload)) {
            $this->redis()->zRem($this->key('reserved', $envelope->getQueue()), $payload);
        }
    }

    public function release(Envelope $envelope, int $delay = 0, ?string $error = null): void
    {
        $this->ack($envelope);
        $record = $this->toRecord($envelope);
        $record['reserved_at'] = null;
        $record['available_at'] = time() + max(0, $delay);
        $record['last_error'] = $error !== null ? $this->excerpt($error) : $envelope->getLastError();
        $this->redis()->zAdd($this->key('delayed', $envelope->getQueue()), $record['available_at'], $this->encode($record));
    }

    public function reject(Envelope $envelope, string $error): void
    {
        $this->ack($envelope);
        $record = $this->toRecord($envelope);
        $record['reserved_at'] = null;
        $record['failed_at'] = time();
        $record['last_error'] = $this->excerpt($error);
        $this->redis()->hSet($this->key('failed'), (string) $record['id'], $this->encode($record));
    }

    public function count(?string $queue = null): array
    {
        $redis = $this->redis();
        $counts = ['ready' => 0, 'delayed' => 0, 'reserved' => 0, 'failed' => 0];

        foreach ($queue !== null ? [$queue] : $this->getQueues() as $name) {
            $counts['ready'] += (int) $redis->zCard($this->key('ready', (string) $name));
            $counts['delayed'] += (int) $redis->zCard($this->key('delayed', (string) $name));
            $counts['reserved'] += (int) $redis->zCard($this->key('reserved', (string) $name));
        }

        if ($queue === null) {
            $counts['failed'] = (int) $redis->hLen($this->key('failed'));
        } else {
            foreach ($this->failedRecords() as $record) {
                $counts['failed'] += ($record['queue'] ?? null) === $queue ? 1 : 0;
            }
        }

        return $counts;
    }

    public function getQueues(): array
    {
        $queues = $this->redis()->sMembers($this->key('queues'));
        $queues = is_array($queues) ? array_map('strval', $queues) : [];
        sort($queues);

        return $queues;
    }

    public function purge(?string $queue = null): int
    {
        $redis = $this->redis();
        $count = 0;

        foreach ($queue !== null ? [$queue] : $this->getQueues() as $name) {
            foreach (['ready', 'delayed', 'reserved'] as $type) {
                $key = $this->key($type, (string) $name);
                $count += (int) $redis->zCard($key);
                $redis->del($key);
            }
        }

        return $count;
    }

    public function getFailed(int $limit = 50): array
    {
        $envelopes = array_map(fn (array $record): Envelope => $this->fromRecord($record, false), $this->failedRecords());
        usort($envelopes, static fn (Envelope $a, Envelope $b): int => (int) $b->getFailedAt() <=> (int) $a->getFailedAt());

        return array_slice($envelopes, 0, max(1, $limit));
    }

    public function retryFailed(string $id): bool
    {
        $redis = $this->redis();
        $payload = $redis->hGet($this->key('failed'), $id);
        $record = is_string($payload) ? json_decode($payload, true) : null;

        if (!is_array($record)) {
            return false;
        }

        $record['attempts'] = 0;
        $record['failed_at'] = null;
        $record['reserved_at'] = null;
        $record['available_at'] = time();
        $redis->sAdd($this->key('queues'), (string) $record['queue']);
        $redis->zAdd($this->key('ready', (string) $record['queue']), $this->score($record), $this->encode($record));
        $redis->hDel($this->key('failed'), $id);

        return true;
    }

    public function forgetFailed(string $id): bool
    {
        return (int) $this->redis()->hDel($this->key('failed'), $id) > 0;
    }

    public function flushFailed(): int
    {
        $redis = $this->redis();
        $count = (int) $redis->hLen($this->key('failed'));
        $redis->del($this->key('failed'));

        return $count;
    }

    protected function migrate(string $queue, int $now): void
    {
        $redis = $this->redis();

        foreach ([$this->key('delayed', $queue), $this->key('reserved', $queue)] as $index => $key) {
            $limit = $index === 0 ? $now : $now - $this->getRetryAfter();
            $payloads = $redis->zRangeByScore($key, '-inf', (string) $limit, ['limit' => [0, 100]]);

            foreach (is_array($payloads) ? $payloads : [] as $payload) {
                if ((int) $redis->zRem($key, (string) $payload) !== 1) {
                    continue;
                }

                $record = json_decode((string) $payload, true);

                if (is_array($record)) {
                    $record['reserved_at'] = null;
                    $redis->zAdd($this->key('ready', $queue), $this->score($record), $this->encode($record));
                }
            }
        }
    }

    protected function failedRecords(): array
    {
        $records = [];
        $all = $this->redis()->hGetAll($this->key('failed'));

        foreach (is_array($all) ? $all : [] as $payload) {
            $record = json_decode((string) $payload, true);

            if (is_array($record)) {
                $records[] = $record;
            }
        }

        return $records;
    }

    protected function score(array $record): float
    {
        return (float) (-((int) ($record['priority'] ?? 0)) * self::PRIORITY_FACTOR + (int) ($record['available_at'] ?? time()));
    }

    protected function encode(array $record): string
    {
        $payload = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($payload === false) {
            throw new TransportException('Unable to encode the queue record "{id}".', 0, null, ['id' => (string) ($record['id'] ?? '')]);
        }

        return $payload;
    }

    protected function key(string $type, ?string $queue = null): string
    {
        $prefix = (string) ($this->options['prefix'] ?? 'neo_queue:');

        return $prefix . $this->name . ':' . $type . ($queue !== null ? ':' . $queue : '');
    }

    protected function redis(): Redis
    {
        if ($this->redis !== null) {
            return $this->redis;
        }

        if (!self::isAvailable()) {
            throw new ConfigurationException('The redis queue transport "{name}" requires the PHP extension "redis" (ext-redis).', 0, null, ['name' => $this->name]);
        }

        try {
            $redis = new Redis();
            $redis->connect((string) ($this->connection['host'] ?? '127.0.0.1'), (int) ($this->connection['port'] ?? 6379), (float) ($this->connection['timeout'] ?? 2.0));

            if (($this->connection['password'] ?? '') !== '') {
                $redis->auth((string) $this->connection['password']);
            }

            $redis->select((int) ($this->connection['database'] ?? 0));
        } catch (Throwable $exception) {
            throw new TransportException('Unable to connect to the redis server of the queue transport "{name}": {error}', 0, $exception, [
                'name' => $this->name,
                'error' => $exception->getMessage(),
            ]);
        }

        return $this->redis = $redis;
    }
}