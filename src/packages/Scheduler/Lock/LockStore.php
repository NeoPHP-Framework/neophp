<?php

declare(strict_types=1);

namespace NeoPHP\Package\Scheduler\Lock;

use NeoPHP\Package\Scheduler\Exception\SchedulerException;

class LockStore
{
    public function __construct(protected string $directory)
    {
    }

    public function acquire(string $name, int $ttl): bool
    {
        return $this->locked($name, function ($handle) use ($ttl): bool {
            $expires = (int) trim((string) stream_get_contents($handle));

            if ($expires > time()) {
                return false;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) (time() + max(1, $ttl)));
            fflush($handle);

            return true;
        });
    }

    public function release(string $name): void
    {
        $this->locked($name, static function ($handle): bool {
            ftruncate($handle, 0);
            fflush($handle);

            return true;
        });
    }

    public function isLocked(string $name): bool
    {
        $file = $this->file($name);

        return is_file($file) && (int) trim((string) @file_get_contents($file)) > time();
    }

    public function getExpiration(string $name): ?int
    {
        $file = $this->file($name);
        $expires = is_file($file) ? (int) trim((string) @file_get_contents($file)) : 0;

        return $expires > time() ? $expires : null;
    }

    protected function locked(string $name, callable $callback): bool
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0777, true) && !is_dir($this->directory)) {
            throw new SchedulerException('Unable to create the lock directory "{directory}".', 0, null, ['directory' => $this->directory]);
        }

        $handle = @fopen($this->file($name), 'c+');

        if ($handle === false) {
            throw new SchedulerException('Unable to open the lock file of the task "{name}".', 0, null, ['name' => $name]);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }

            return (bool) $callback($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    protected function file(string $name): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . sha1($name) . '.lock';
    }
}