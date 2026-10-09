<?php

declare(strict_types=1);

namespace NeoPHP\Component\Logger\Writer;

use NeoPHP\Component\Logger\Exception\LoggerException;
use ZipArchive;

class Archiver
{
    public const FORMATS = ['zip', 'gz'];

    protected string $format;

    public function __construct(string $format = 'zip')
    {
        $format = strtolower(ltrim($format, '.'));

        if (!in_array($format, self::FORMATS, true)) {
            throw new LoggerException('Unsupported archive extension "{format}". Expected one of: {formats}.', 0, null, [
                'format' => $format,
                'formats' => implode(', ', self::FORMATS),
            ]);
        }

        if ($format === 'zip' && !class_exists(ZipArchive::class)) {
            throw new LoggerException('The "zip" archive extension requires the PHP "zip" extension. Enable it in php.ini or use "gz".');
        }

        if ($format === 'gz' && !function_exists('gzencode')) {
            throw new LoggerException('The "gz" archive extension requires the PHP "zlib" extension.');
        }

        $this->format = $format;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function archive(string $file): string
    {
        $target = $file . '.' . $this->format;

        if ($this->format === 'zip') {
            $zip = new ZipArchive();

            if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new LoggerException('Unable to create the archive "{archive}".', 0, null, ['archive' => $target]);
            }

            $zip->addFile($file, basename($file));
            $zip->close();
        } else {
            $content = file_get_contents($file);

            if ($content === false || file_put_contents($target, gzencode($content, 9)) === false) {
                throw new LoggerException('Unable to create the archive "{archive}".', 0, null, ['archive' => $target]);
            }
        }

        unlink($file);

        return $target;
    }
}