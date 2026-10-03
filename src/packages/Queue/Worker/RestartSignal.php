<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Worker;

use NeoPHP\Package\Queue\Exception\QueueException;

class RestartSignal
{
    public function __construct(protected string $file)
    {
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function send(): float
    {
        $directory = dirname($this->file);

        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new QueueException('Unable to create the directory "{directory}".', 0, null, ['directory' => $directory]);
        }

        $time = microtime(true);

        if (file_put_contents($this->file, sprintf('%.6F', $time), LOCK_EX) === false) {
            throw new QueueException('Unable to write the restart signal "{file}".', 0, null, ['file' => $this->file]);
        }

        return $time;
    }

    public function get(): ?float
    {
        if (!is_file($this->file)) {
            return null;
        }

        $content = trim((string) @file_get_contents($this->file));

        return $content === '' ? null : (float) $content;
    }
}