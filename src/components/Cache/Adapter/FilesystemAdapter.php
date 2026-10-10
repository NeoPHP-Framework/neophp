<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cache\Adapter;

use FilesystemIterator;
use NeoPHP\Component\Cache\Contract\AbstractAdapter;
use NeoPHP\Component\Cache\Exception\CacheException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class FilesystemAdapter extends AbstractAdapter
{
    public const EXTENSION = '.cache';

    public const LOCK_DIRECTORY = '.locks';

    public const NAMESPACE_PREFIX = 'ns-';

    protected string $directory;

    protected array $locks = [];

    public function __construct(string $directory, string $namespace = '')
    {
        if (trim($directory) === '') {
            throw new CacheException('The directory of the filesystem cache adapter cannot be empty.');
        }

        $this->directory = rtrim($directory, '/\\');
        $this->namespace = $namespace;
    }

    public function getName(): string
    {
        return 'filesystem';
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    public function fetch(string $id): ?string
    {
        $file = $this->path($id);

        if (!is_file($file)) {
            return null;
        }

        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return null;
        }

        $header = fgets($handle);
        $expiresAt = $header === false ? 0 : (int) trim($header);

        if ($header === false || $this->isExpired($expiresAt)) {
            fclose($handle);
            @unlink($file);

            return null;
        }

        $data = stream_get_contents($handle);
        fclose($handle);

        return $data === false ? null : $data;
    }

    public function save(string $id, string $data, ?int $expiresAt): bool
    {
        $file = $this->path($id);
        $directory = dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new CacheException('The cache directory "{directory}" cannot be created.', 0, null, ['directory' => $directory]);
        }

        $temporary = $directory . DIRECTORY_SEPARATOR . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temporary, ($expiresAt ?? 0) . "\n" . $data) === false) {
            throw new CacheException('The cache file "{file}" cannot be written.', 0, null, ['file' => $temporary]);
        }

        if (!@rename($temporary, $file)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    public function remove(string $id): bool
    {
        $file = $this->path($id);

        return !is_file($file) || @unlink($file) || !is_file($file);
    }

    public function clear(): bool
    {
        $success = true;

        foreach ($this->files() as $file) {
            if (str_ends_with($file->getFilename(), self::EXTENSION) && !@unlink($file->getPathname())) {
                $success = false;
            }
        }

        return $success;
    }

    public function prune(): int
    {
        $pruned = 0;

        foreach ($this->files() as $file) {
            $name = $file->getFilename();

            if (str_ends_with($name, '.tmp') && $file->getMTime() < time() - 3600) {
                @unlink($file->getPathname());
                continue;
            }

            if (!str_ends_with($name, self::EXTENSION)) {
                continue;
            }

            $handle = @fopen($file->getPathname(), 'rb');

            if ($handle === false) {
                continue;
            }

            $header = fgets($handle);
            fclose($handle);

            if (($header === false || $this->isExpired((int) trim($header))) && @unlink($file->getPathname())) {
                $pruned++;
            }
        }

        return $pruned;
    }

    public function lock(string $id, float $timeout): bool
    {
        if (isset($this->locks[$id])) {
            return true;
        }

        $directory = $this->root() . DIRECTORY_SEPARATOR . self::LOCK_DIRECTORY;

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        $handle = @fopen($directory . DIRECTORY_SEPARATOR . sha1($id) . '.lock', 'c');

        if ($handle === false) {
            return false;
        }

        if (!$this->waitFor(static fn (): bool => flock($handle, LOCK_EX | LOCK_NB), $timeout)) {
            fclose($handle);

            return false;
        }

        $this->locks[$id] = $handle;

        return true;
    }

    public function unlock(string $id): void
    {
        if (!isset($this->locks[$id])) {
            return;
        }

        flock($this->locks[$id], LOCK_UN);
        fclose($this->locks[$id]);
        unset($this->locks[$id]);
    }

    protected function path(string $id): string
    {
        $hash = sha1($this->namespace . "\0" . $id);

        return $this->root() . DIRECTORY_SEPARATOR . substr($hash, 0, 2) . DIRECTORY_SEPARATOR . substr($hash, 2, 2) . DIRECTORY_SEPARATOR . $hash . self::EXTENSION;
    }

    protected function root(): string
    {
        return $this->namespace === '' ? $this->directory : $this->directory . DIRECTORY_SEPARATOR . self::NAMESPACE_PREFIX . substr(sha1($this->namespace), 0, 16);
    }

    protected function files(): iterable
    {
        $root = $this->root();

        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
        $namespaces = $root . DIRECTORY_SEPARATOR . self::NAMESPACE_PREFIX;

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && ($this->namespace !== '' || !str_starts_with($file->getPathname(), $namespaces))) {
                $files[] = $file;
            }
        }

        return $files;
    }
}