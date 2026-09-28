<?php

declare(strict_types=1);

namespace NeoPHP\Component\Logger\Writer;

use DateTimeImmutable;
use NeoPHP\Component\Logger\Exception\LoggerException;

class FileWriter
{
    public const PERIODS = [
        'minute' => 'Y-m-d_H-i',
        'hour' => 'Y-m-d_H',
        'day' => 'Y-m-d',
        'week' => 'o-\WW',
        'month' => 'Y-m',
        'year' => 'Y',
    ];

    protected ?int $maxSize;

    protected ?string $every;

    public function __construct(
        protected string $file,
        protected bool $rotation = false,
        int|string|null $maxSize = null,
        ?string $every = null,
        protected ?int $maxFiles = null,
        protected ?Archiver $archiver = null,
    ) {
        $this->maxSize = static::parseSize($maxSize);
        $every = $every === null || $every === '' ? null : strtolower($every);

        if ($every !== null && !isset(self::PERIODS[$every])) {
            throw new LoggerException('Invalid rotation period "{every}". Expected one of: {periods}.', 0, null, [
                'every' => $every,
                'periods' => implode(', ', array_keys(self::PERIODS)),
            ]);
        }

        $this->every = $every;
    }

    public static function parseSize(int|string|null $size): ?int
    {
        if ($size === null || $size === '' || $size === 0 || $size === '0') {
            return null;
        }

        if (is_int($size)) {
            return $size;
        }

        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*([KMG]?)B?\s*$/i', $size, $m) !== 1) {
            throw new LoggerException('Invalid file size "{size}". Expected a number of bytes or a value like "500K", "10M", "1G".', 0, null, ['size' => $size]);
        }

        $multiplier = match (strtoupper($m[2])) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };

        return (int) round((float) $m[1] * $multiplier);
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function write(string $line, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();
        $directory = dirname($this->file);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new LoggerException('Unable to create the log directory "{directory}".', 0, null, ['directory' => $directory]);
        }

        if ($this->rotation && is_file($this->file)) {
            $this->rotateIfNeeded(strlen($line), $now);
        }

        if (@file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new LoggerException('Unable to write to the log file "{file}".', 0, null, ['file' => $this->file]);
        }
    }

    public function rotateIfNeeded(int $incomingBytes, DateTimeImmutable $now): ?string
    {
        clearstatcache(true, $this->file);

        $modified = (new DateTimeImmutable())->setTimestamp((int) filemtime($this->file))->setTimezone($now->getTimezone());
        $suffix = null;

        if ($this->every !== null) {
            $format = self::PERIODS[$this->every];

            if ($modified->format($format) !== $now->format($format)) {
                $suffix = $modified->format($format);
            }
        }

        if ($suffix === null && $this->maxSize !== null && (int) filesize($this->file) + $incomingBytes > $this->maxSize) {
            $suffix = $now->format('Y-m-d_H-i-s');
        }

        return $suffix === null ? null : $this->rotate($suffix);
    }

    public function rotate(string $suffix): string
    {
        $info = pathinfo($this->file);
        $base = $info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'] . '-' . $suffix;
        $extension = isset($info['extension']) ? '.' . $info['extension'] : '';
        $target = $base . $extension;
        $index = 1;

        while (is_file($target) || ($this->archiver !== null && is_file($target . '.' . $this->archiver->getFormat()))) {
            $target = $base . '-' . $index++ . $extension;
        }

        if (!@rename($this->file, $target)) {
            throw new LoggerException('Unable to rotate the log file "{file}".', 0, null, ['file' => $this->file]);
        }

        if ($this->archiver !== null) {
            $target = $this->archiver->archive($target);
        }

        $this->cleanup();

        return $target;
    }

    protected function cleanup(): void
    {
        if ($this->maxFiles === null || $this->maxFiles < 1) {
            return;
        }

        $info = pathinfo($this->file);
        $files = glob($info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'] . '-*') ?: [];

        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a) ?: strcmp($b, $a));

        foreach (array_slice($files, $this->maxFiles) as $file) {
            @unlink($file);
        }
    }
}