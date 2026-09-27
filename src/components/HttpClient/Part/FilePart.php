<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Part;

use NeoPHP\Component\HttpClient\Exception\InvalidOptionException;

class FilePart
{
    public function __construct(protected string $path, protected ?string $filename = null, protected ?string $contentType = null, protected ?string $content = null)
    {
        if ($content === null && !is_file($path)) {
            throw new InvalidOptionException('The file "{path}" to upload does not exist.', 0, null, ['path' => $path]);
        }
    }

    public static function fromPath(string $path, ?string $filename = null, ?string $contentType = null): static
    {
        return new static($path, $filename, $contentType);
    }

    public static function fromData(string $content, string $filename, ?string $contentType = null): static
    {
        return new static('', $filename, $contentType, $content);
    }

    public function getFilename(): string
    {
        return $this->filename ?? basename($this->path);
    }

    public function getContentType(): string
    {
        if ($this->contentType !== null) {
            return $this->contentType;
        }

        if ($this->content === null && function_exists('mime_content_type')) {
            $type = @mime_content_type($this->path);

            if (is_string($type) && $type !== '') {
                return $type;
            }
        }

        return 'application/octet-stream';
    }

    public function getContent(): string
    {
        if ($this->content !== null) {
            return $this->content;
        }

        $content = @file_get_contents($this->path);

        if ($content === false) {
            throw new InvalidOptionException('The file "{path}" to upload cannot be read.', 0, null, ['path' => $this->path]);
        }

        return $content;
    }
}