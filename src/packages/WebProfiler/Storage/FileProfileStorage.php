<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Storage;

use JsonException;
use NeoPHP\Package\WebProfiler\Contract\ProfileStorageInterface;
use NeoPHP\Package\WebProfiler\Exception\StorageException;
use NeoPHP\Package\WebProfiler\Model\Profile;

class FileProfileStorage implements ProfileStorageInterface
{
    public const INDEX = 'index.jsonl';

    public const TOKEN_PATTERN = '/^[a-zA-Z0-9]{1,64}$/';

    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(protected string $directory, protected int $maxProfiles = 200, protected int $lifetime = 86400)
    {
        $this->directory = rtrim($directory, '/\\');
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    public function write(Profile $profile): void
    {
        $this->assertToken($profile->getToken());
        $this->ensureDirectory();

        $content = json_encode($profile->toArray(), self::JSON_FLAGS);

        if ($content === false || file_put_contents($this->file($profile->getToken()), $content, LOCK_EX) === false) {
            throw new StorageException('Unable to write the profile "{token}" in "{directory}".', 0, null, ['token' => $profile->getToken(), 'directory' => $this->directory]);
        }

        $line = json_encode($profile->getSummary(), self::JSON_FLAGS) . "\n";

        if (file_put_contents($this->index(), $line, FILE_APPEND | LOCK_EX) === false) {
            throw new StorageException('Unable to update the profiler index "{file}".', 0, null, ['file' => $this->index()]);
        }

        $this->purge();
    }

    public function read(string $token): ?Profile
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1 || !is_file($this->file($token))) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($this->file($token)), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) ? Profile::fromArray($data) : null;
    }

    public function find(int $limit = 50, array $filters = []): array
    {
        $results = [];

        foreach (array_reverse($this->entries()) as $entry) {
            if (!$this->matches($entry, $filters)) {
                continue;
            }

            $results[] = $entry;

            if ($limit > 0 && count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    public function latest(): ?Profile
    {
        $entry = $this->find(1)[0] ?? null;

        return $entry === null ? null : $this->read((string) $entry['token']);
    }

    public function purge(): int
    {
        $entries = $this->entries();
        $limit = time() - $this->lifetime;
        $keep = [];
        $removed = 0;

        foreach ($entries as $index => $entry) {
            $expired = $this->lifetime > 0 && (int) ($entry['time'] ?? 0) < $limit;
            $overflow = $this->maxProfiles > 0 && count($entries) - $index > $this->maxProfiles;

            if ($expired || $overflow || !is_file($this->file((string) $entry['token']))) {
                @unlink($this->file((string) $entry['token']));
                $removed++;
                continue;
            }

            $keep[] = $entry;
        }

        if ($removed > 0) {
            $this->rewrite($keep);
        }

        return $removed;
    }

    public function clear(): int
    {
        $count = 0;

        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }

        @unlink($this->index());

        return $count;
    }

    protected function entries(): array
    {
        if (!is_file($this->index())) {
            return [];
        }

        $entries = [];

        foreach (file($this->index(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = json_decode($line, true);

            if (is_array($entry) && isset($entry['token']) && preg_match(self::TOKEN_PATTERN, (string) $entry['token']) === 1) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    protected function matches(array $entry, array $filters): bool
    {
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $matches = match ($key) {
                'ip' => str_contains((string) ($entry['ip'] ?? ''), (string) $value),
                'url' => stripos((string) ($entry['url'] ?? ''), (string) $value) !== false,
                'method' => strtoupper((string) ($entry['method'] ?? '')) === strtoupper((string) $value),
                'status' => $this->matchesStatus((int) ($entry['status'] ?? 0), (string) $value),
                'token' => str_starts_with((string) $entry['token'], (string) $value),
                'route' => (string) ($entry['route'] ?? '') === (string) $value,
                default => true,
            };

            if (!$matches) {
                return false;
            }
        }

        return true;
    }

    protected function matchesStatus(int $status, string $filter): bool
    {
        if (preg_match('/^([1-5])xx$/i', $filter, $matches) === 1) {
            return intdiv($status, 100) === (int) $matches[1];
        }

        return $status === (int) $filter;
    }

    protected function rewrite(array $entries): void
    {
        $content = '';

        foreach ($entries as $entry) {
            $content .= json_encode($entry, self::JSON_FLAGS) . "\n";
        }

        file_put_contents($this->index(), $content, LOCK_EX);
    }

    protected function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new StorageException('Unable to create the profiler directory "{directory}".', 0, null, ['directory' => $this->directory]);
        }
    }

    protected function assertToken(string $token): void
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new StorageException('Invalid profile token "{token}".', 0, null, ['token' => $token]);
        }
    }

    protected function file(string $token): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $token . '.json';
    }

    protected function index(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . self::INDEX;
    }
}