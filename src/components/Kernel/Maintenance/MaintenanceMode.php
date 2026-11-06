<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Maintenance;

use NeoPHP\Component\Kernel\Exception\KernelException;

class MaintenanceMode
{
    public const FILE = 'var/maintenance.json';

    public const DEFAULT_RETRY = 60;

    public function __construct(protected string $rootPath)
    {
    }

    public function getFile(): string
    {
        return rtrim($this->rootPath, '/\\') . '/' . self::FILE;
    }

    public function isEnabled(): bool
    {
        return is_file($this->getFile());
    }

    public function enable(int $retry = self::DEFAULT_RETRY, array $allowedIps = [], string $message = ''): void
    {
        $file = $this->getFile();
        $directory = dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new KernelException('Unable to create the directory "{directory}".', 0, null, ['directory' => $directory]);
        }

        $content = json_encode(['since' => time(), 'retry' => max(1, $retry), 'allowed_ips' => $allowedIps, 'message' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if ($content === false || file_put_contents($temporary, $content . "\n") === false || !rename($temporary, $file)) {
            @unlink($temporary);

            throw new KernelException('Unable to write the maintenance file "{file}".', 0, null, ['file' => $file]);
        }
    }

    public function disable(): bool
    {
        $file = $this->getFile();

        return !is_file($file) || @unlink($file);
    }

    public function read(): array
    {
        $file = $this->getFile();
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        $data = is_array($data) ? $data : [];

        return [
            'since' => (int) ($data['since'] ?? time()),
            'retry' => max(1, (int) ($data['retry'] ?? self::DEFAULT_RETRY)),
            'allowed_ips' => array_values(array_map('strval', (array) ($data['allowed_ips'] ?? []))),
            'message' => (string) ($data['message'] ?? ''),
        ];
    }
}