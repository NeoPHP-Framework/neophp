<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Cache;

use NeoPHP\Component\Kernel\Exception\KernelException;

class ResourceCache
{
    public const MISSING = -1;

    public function __construct(protected string $file, protected bool $debug = false)
    {
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function load(callable $builder): array
    {
        $cached = $this->read();

        if ($cached !== null && (!$this->debug || $this->isFresh((array) ($cached['resources'] ?? [])))) {
            return (array) ($cached['data'] ?? []);
        }

        [$data, $resources] = $builder();
        $this->write((array) $data, (array) $resources);

        return (array) $data;
    }

    public function clear(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    protected function read(): ?array
    {
        if (!is_file($this->file)) {
            return null;
        }

        $data = @include $this->file;

        return is_array($data) ? $data : null;
    }

    protected function isFresh(array $resources): bool
    {
        foreach ($resources as $path => $time) {
            if ((int) $time === self::MISSING) {
                if (file_exists((string) $path)) {
                    return false;
                }

                continue;
            }

            if (!file_exists((string) $path) || (int) filemtime((string) $path) !== (int) $time) {
                return false;
            }
        }

        return true;
    }

    protected function write(array $data, array $resources): void
    {
        $directory = dirname($this->file);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new KernelException('Unable to create the cache directory "{directory}".', 0, null, ['directory' => $directory]);
        }

        $temporary = $this->file . '.' . uniqid('', true) . '.tmp';
        $content = '<?php return ' . var_export(['resources' => $resources, 'data' => $data], true) . ";\n";

        if (file_put_contents($temporary, $content) === false || !rename($temporary, $this->file)) {
            @unlink($temporary);

            throw new KernelException('Unable to write the cache file "{file}".', 0, null, ['file' => $this->file]);
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->file, true);
        }
    }
}