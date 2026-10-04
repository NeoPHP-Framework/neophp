<?php

declare(strict_types=1);

namespace NeoPHP\Component\Upload;

use Closure;
use NeoPHP\Component\Upload\Contract\AbstractUploader;

class UploadManager extends AbstractUploader
{
    public static function fromConfig(array $config, array $defaults = [], ?Closure $basePath = null): self
    {
        $config = array_replace($defaults, array_filter($config, static fn (mixed $value): bool => $value !== null));
        $mimeTypes = $config['mime_types'] ?? self::DEFAULT_MIME_TYPES;

        return new self(
            (string) $config['path'],
            (string) ($config['public_url'] ?? '/uploads'),
            (int) ($config['max_size'] ?? self::DEFAULT_MAX_SIZE),
            is_array($mimeTypes) && $mimeTypes !== [] ? $mimeTypes : self::DEFAULT_MIME_TYPES,
            $basePath,
        );
    }
}