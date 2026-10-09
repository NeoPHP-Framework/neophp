<?php

declare(strict_types=1);

namespace NeoPHP\Component\Asset\Manifest;

use NeoPHP\Component\Asset\Exception\AssetException;

class Manifest
{
    protected ?array $entries = null;

    protected bool $dirty = false;

    public function __construct(protected string $file)
    {
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function all(): array
    {
        return $this->entries ??= $this->load();
    }

    public function get(string $path): ?string
    {
        $entry = $this->all()[$path] ?? null;

        return is_string($entry) ? $entry : null;
    }

    public function set(string $path, string $url): static
    {
        if ($this->get($path) !== $url) {
            $this->entries[$path] = $url;
            $this->dirty = true;
        }

        return $this;
    }

    public function remove(string $path): static
    {
        if (array_key_exists($path, $this->all())) {
            unset($this->entries[$path]);
            $this->dirty = true;
        }

        return $this;
    }

    public function clear(): static
    {
        $this->entries = [];
        $this->dirty = true;

        return $this;
    }

    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }

        $entries = $this->all();
        ksort($entries);

        $directory = dirname($this->file);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new AssetException('Unable to create the directory "{directory}".', 0, null, ['directory' => $directory]);
        }

        $json = json_encode($entries === [] ? new \stdClass() : $entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $temporary = $this->file . '.' . uniqid('', true) . '.tmp';

        if ($json === false || file_put_contents($temporary, $json . "\n") === false || !rename($temporary, $this->file)) {
            @unlink($temporary);

            throw new AssetException('Unable to write the asset manifest "{file}".', 0, null, ['file' => $this->file]);
        }

        $this->dirty = false;
    }

    protected function load(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $entries = json_decode((string) file_get_contents($this->file), true);

        if (!is_array($entries)) {
            throw new AssetException('The asset manifest "{file}" is not valid JSON. Run "php bin/neo asset:reload".', 0, null, ['file' => $this->file]);
        }

        return $entries;
    }
}