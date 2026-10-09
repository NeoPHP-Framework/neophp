<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Transport;

use NeoPHP\Package\Queue\Contract\AbstractTransport;
use NeoPHP\Package\Queue\Contract\SetupableTransportInterface;
use NeoPHP\Package\Queue\Exception\TransportException;
use NeoPHP\Package\Queue\Message\Envelope;
use NeoPHP\Package\Queue\Serializer\MessageSerializer;

class FilesystemTransport extends AbstractTransport implements SetupableTransportInterface
{
    public const EXTENSION = '.job';

    public const RESERVED_DIRECTORY = '.reserved';

    public const FAILED_DIRECTORY = 'failed';

    public function __construct(string $name, MessageSerializer $serializer, protected string $directory, array $options = [])
    {
        parent::__construct($name, $serializer, $options);
        $this->directory = rtrim($directory, '/\\');
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    public function setup(): void
    {
        $this->ensureDirectory($this->directory);
        $this->ensureDirectory($this->failedDirectory());
    }

    public function send(Envelope $envelope): Envelope
    {
        $this->assertQueueName($envelope->getQueue());
        $envelope->setId($this->generateId());
        $this->writeReady($this->toRecord($envelope));

        return $envelope;
    }

    public function get(array $queues = [], int $limit = 1): array
    {
        $now = time();
        $envelopes = [];

        foreach ($this->queues($queues) as $queue) {
            $this->assertQueueName($queue);
            $this->reclaim($queue, $now);
            $candidates = [];

            foreach ($this->readyFiles($queue) as $file) {
                $meta = $this->parseName(basename($file));

                if ($meta !== null && $meta['available_at'] <= $now) {
                    $candidates[] = [$file, $meta];
                }
            }

            usort($candidates, static fn (array $a, array $b): int => [$b[1]['priority'], $a[1]['available_at'], $a[1]['id']] <=> [$a[1]['priority'], $b[1]['available_at'], $b[1]['id']]);

            foreach ($candidates as [$file, $meta]) {
                $reserved = $this->reservedDirectory($queue) . DIRECTORY_SEPARATOR . $meta['id'] . self::EXTENSION;
                $this->ensureDirectory(dirname($reserved));

                if (!@rename($file, $reserved)) {
                    continue;
                }

                $record = $this->readRecord($reserved);

                if ($record === null) {
                    @unlink($reserved);
                    continue;
                }

                $record['attempts'] = (int) ($record['attempts'] ?? 0) + 1;
                $record['reserved_at'] = $now;
                $this->writeAtomic($reserved, $record);
                $envelopes[] = $this->fromRecord($record);

                if (count($envelopes) >= $limit) {
                    return $envelopes;
                }
            }
        }

        return $envelopes;
    }

    public function ack(Envelope $envelope): void
    {
        $file = $this->reservedDirectory($envelope->getQueue()) . DIRECTORY_SEPARATOR . $envelope->getId() . self::EXTENSION;

        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function release(Envelope $envelope, int $delay = 0, ?string $error = null): void
    {
        $reserved = $this->reservedDirectory($envelope->getQueue()) . DIRECTORY_SEPARATOR . $envelope->getId() . self::EXTENSION;
        $record = $this->toRecord($envelope);
        $record['reserved_at'] = null;
        $record['available_at'] = time() + max(0, $delay);
        $record['last_error'] = $error !== null ? $this->excerpt($error) : $envelope->getLastError();
        $this->writeReady($record);

        if (is_file($reserved)) {
            @unlink($reserved);
        }
    }

    public function reject(Envelope $envelope, string $error): void
    {
        $record = $this->toRecord($envelope);
        $record['reserved_at'] = null;
        $record['failed_at'] = time();
        $record['last_error'] = $this->excerpt($error);
        $this->ensureDirectory($this->failedDirectory());
        $this->writeAtomic($this->failedDirectory() . DIRECTORY_SEPARATOR . $record['id'] . self::EXTENSION, $record);
        $this->ack($envelope);
    }

    public function count(?string $queue = null): array
    {
        $now = time();
        $counts = ['ready' => 0, 'delayed' => 0, 'reserved' => 0, 'failed' => 0];

        foreach ($queue !== null ? [$queue] : $this->getQueues() as $name) {
            foreach ($this->readyFiles((string) $name) as $file) {
                $meta = $this->parseName(basename($file));

                if ($meta !== null) {
                    $counts[$meta['available_at'] <= $now ? 'ready' : 'delayed']++;
                }
            }

            $counts['reserved'] += count(glob($this->reservedDirectory((string) $name) . DIRECTORY_SEPARATOR . '*' . self::EXTENSION) ?: []);
        }

        foreach ($this->failedFiles() as $file) {
            if ($queue === null || ($this->readRecord($file)['queue'] ?? null) === $queue) {
                $counts['failed']++;
            }
        }

        return $counts;
    }

    public function getQueues(): array
    {
        $queues = [];

        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $directory) {
            $name = basename($directory);

            if ($name !== self::FAILED_DIRECTORY && !str_starts_with($name, '.')) {
                $queues[] = $name;
            }
        }

        sort($queues);

        return $queues;
    }

    public function purge(?string $queue = null): int
    {
        $count = 0;

        foreach ($queue !== null ? [$queue] : $this->getQueues() as $name) {
            $this->assertQueueName((string) $name);

            foreach ([...$this->readyFiles((string) $name), ...(glob($this->reservedDirectory((string) $name) . DIRECTORY_SEPARATOR . '*' . self::EXTENSION) ?: [])] as $file) {
                $count += @unlink($file) ? 1 : 0;
            }
        }

        return $count;
    }

    public function getFailed(int $limit = 50): array
    {
        $envelopes = [];

        foreach ($this->failedFiles() as $file) {
            $record = $this->readRecord($file);

            if ($record !== null) {
                $envelopes[] = $this->fromRecord($record, false);
            }
        }

        usort($envelopes, static fn (Envelope $a, Envelope $b): int => [(int) $b->getFailedAt(), (string) $b->getId()] <=> [(int) $a->getFailedAt(), (string) $a->getId()]);

        return array_slice($envelopes, 0, max(1, $limit));
    }

    public function retryFailed(string $id): bool
    {
        $file = $this->failedFile($id);
        $record = $file !== null ? $this->readRecord($file) : null;

        if ($file === null || $record === null) {
            return false;
        }

        $record['attempts'] = 0;
        $record['failed_at'] = null;
        $record['reserved_at'] = null;
        $record['available_at'] = time();
        $this->writeReady($record);

        return @unlink($file);
    }

    public function forgetFailed(string $id): bool
    {
        $file = $this->failedFile($id);

        return $file !== null && @unlink($file);
    }

    public function flushFailed(): int
    {
        $count = 0;

        foreach ($this->failedFiles() as $file) {
            $count += @unlink($file) ? 1 : 0;
        }

        return $count;
    }

    protected function reclaim(string $queue, int $now): void
    {
        $files = glob($this->reservedDirectory($queue) . DIRECTORY_SEPARATOR . '*' . self::EXTENSION) ?: [];

        if ($files === []) {
            return;
        }

        $this->ensureDirectory($this->directory);
        $lock = fopen($this->directory . DIRECTORY_SEPARATOR . '.lock', 'c');

        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }

            return;
        }

        try {
            foreach ($files as $file) {
                $record = $this->readRecord($file);

                if ($record === null || (int) ($record['reserved_at'] ?? 0) > $now - $this->getRetryAfter()) {
                    continue;
                }

                $record['reserved_at'] = null;
                $this->writeReady($record);
                @unlink($file);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected function writeReady(array $record): void
    {
        $queue = (string) $record['queue'];
        $this->assertQueueName($queue);
        $file = sprintf('%s%s%010d_%d_%s%s', $this->queueDirectory($queue), DIRECTORY_SEPARATOR, (int) $record['available_at'], (int) $record['priority'], (string) $record['id'], self::EXTENSION);
        $this->writeAtomic($file, $record);
    }

    protected function writeAtomic(string $file, array $record): void
    {
        $this->ensureDirectory(dirname($file));
        $temporary = dirname($file) . DIRECTORY_SEPARATOR . '.' . basename($file) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $content = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($content === false || file_put_contents($temporary, $content, LOCK_EX) === false || !@rename($temporary, $file)) {
            @unlink($temporary);

            throw new TransportException('Unable to write the queue file "{file}".', 0, null, ['file' => $file]);
        }
    }

    protected function readRecord(string $file): ?array
    {
        $handle = @fopen($file, 'r');

        if ($handle === false) {
            return null;
        }

        flock($handle, LOCK_SH);
        $content = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        $record = json_decode((string) $content, true);

        return is_array($record) ? $record : null;
    }

    protected function parseName(string $name): ?array
    {
        if (preg_match('/^(\d+)_(-?\d+)_([A-Za-z0-9]+)\.job$/', $name, $matches) !== 1) {
            return null;
        }

        return ['available_at' => (int) $matches[1], 'priority' => (int) $matches[2], 'id' => $matches[3]];
    }

    protected function readyFiles(string $queue): array
    {
        return glob($this->queueDirectory($queue) . DIRECTORY_SEPARATOR . '*' . self::EXTENSION) ?: [];
    }

    protected function failedFiles(): array
    {
        return glob($this->failedDirectory() . DIRECTORY_SEPARATOR . '*' . self::EXTENSION) ?: [];
    }

    protected function failedFile(string $id): ?string
    {
        if (preg_match('/^[A-Za-z0-9]+$/', $id) !== 1) {
            return null;
        }

        $file = $this->failedDirectory() . DIRECTORY_SEPARATOR . $id . self::EXTENSION;

        return is_file($file) ? $file : null;
    }

    protected function queueDirectory(string $queue): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $queue;
    }

    protected function reservedDirectory(string $queue): string
    {
        return $this->queueDirectory($queue) . DIRECTORY_SEPARATOR . self::RESERVED_DIRECTORY;
    }

    protected function failedDirectory(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . self::FAILED_DIRECTORY;
    }

    protected function generateId(): string
    {
        return sprintf('%013x%s', (int) (microtime(true) * 1000000), bin2hex(random_bytes(4)));
    }

    protected function assertQueueName(string $queue): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $queue) !== 1 || $queue === self::FAILED_DIRECTORY) {
            throw new TransportException('The queue name "{queue}" is not valid for the filesystem transport (letters, digits, "_" and "-", not "failed").', 0, null, ['queue' => $queue]);
        }
    }

    protected function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new TransportException('Unable to create the queue directory "{directory}".', 0, null, ['directory' => $directory]);
        }
    }
}