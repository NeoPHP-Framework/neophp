<?php

declare(strict_types=1);

namespace NeoPHP\Package\Scheduler\History;

use NeoPHP\Package\Scheduler\Exception\SchedulerException;

class HistoryStore
{
    public const OUTPUT_LENGTH = 2000;

    public function __construct(protected string $file, protected int $max = 500, protected ?string $heartbeatFile = null)
    {
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function append(array $record): void
    {
        $this->ensureDirectory(dirname($this->file));
        $record['output'] = mb_substr((string) ($record['output'] ?? ''), -self::OUTPUT_LENGTH);
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($line === false) {
            return;
        }

        $handle = @fopen($this->file, 'c+');

        if ($handle === false) {
            throw new SchedulerException('Unable to open the scheduler history "{file}".', 0, null, ['file' => $this->file]);
        }

        try {
            flock($handle, LOCK_EX);
            $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($handle)), static fn (string $item): bool => trim($item) !== ''));
            $lines[] = $line;

            if (count($lines) > $this->max) {
                $lines = array_slice($lines, -$this->max);
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, implode("\n", $lines) . "\n");
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function all(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $records = [];

        foreach (file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $record = json_decode($line, true);

            if (is_array($record)) {
                $records[] = $record;
            }
        }

        return $records;
    }

    public function recent(int $limit = 20, ?string $name = null): array
    {
        $records = array_reverse($this->all());

        if ($name !== null) {
            $records = array_values(array_filter($records, static fn (array $record): bool => ($record['name'] ?? null) === $name));
        }

        return array_slice($records, 0, max(1, $limit));
    }

    public function lastRuns(): array
    {
        $last = [];

        foreach ($this->all() as $record) {
            $last[(string) ($record['name'] ?? '')] = $record;
        }

        return $last;
    }

    public function clear(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
        }
    }

    public function beat(?int $time = null): void
    {
        if ($this->heartbeatFile === null) {
            return;
        }

        $this->ensureDirectory(dirname($this->heartbeatFile));
        @file_put_contents($this->heartbeatFile, (string) ($time ?? time()), LOCK_EX);
    }

    public function getLastBeat(): ?int
    {
        if ($this->heartbeatFile === null || !is_file($this->heartbeatFile)) {
            return null;
        }

        $value = (int) trim((string) @file_get_contents($this->heartbeatFile));

        return $value > 0 ? $value : null;
    }

    protected function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new SchedulerException('Unable to create the directory "{directory}".', 0, null, ['directory' => $directory]);
        }
    }
}