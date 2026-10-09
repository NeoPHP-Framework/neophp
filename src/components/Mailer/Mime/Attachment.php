<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Mime;

use NeoPHP\Component\Mailer\Exception\MailerException;

class Attachment
{
    public const MIME_TYPES = [
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'html' => 'text/html',
        'htm' => 'text/html',
        'css' => 'text/css',
        'ics' => 'text/calendar',
        'xml' => 'application/xml',
        'json' => 'application/json',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'gz' => 'application/gzip',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
    ];

    public function __construct(
        protected string $body,
        protected string $filename,
        protected string $contentType = 'application/octet-stream',
        protected bool $inline = false,
    ) {
        if (preg_match('/[\r\n\0]/', $filename) === 1) {
            throw new MailerException('The attachment name "{name}" contains a line break.', 0, null, ['name' => addcslashes($filename, "\r\n\0")]);
        }

        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9!\#$&^_.+-]*/[A-Za-z0-9][A-Za-z0-9!\#$&^_.+-]*$#', $contentType) !== 1) {
            throw new MailerException('The content type "{type}" of the attachment "{name}" is not valid (expected "type/subtype").', 0, null, ['type' => addcslashes($contentType, "\r\n\0"), 'name' => $filename]);
        }
    }

    public static function fromPath(string $path, ?string $filename = null, ?string $contentType = null, bool $inline = false): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new MailerException('The file "{path}" does not exist or is not readable.', 0, null, ['path' => $path]);
        }

        $body = file_get_contents($path);

        if ($body === false) {
            throw new MailerException('Unable to read the file "{path}".', 0, null, ['path' => $path]);
        }

        return new self($body, $filename ?? basename($path), $contentType ?? self::guessContentType($path), $inline);
    }

    public static function guessContentType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (isset(self::MIME_TYPES[$extension])) {
            return self::MIME_TYPES[$extension];
        }

        if (is_file($path) && function_exists('mime_content_type')) {
            $type = @mime_content_type($path);

            if (is_string($type) && $type !== '') {
                return $type;
            }
        }

        return 'application/octet-stream';
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function isInline(): bool
    {
        return $this->inline;
    }

    public function getSize(): int
    {
        return strlen($this->body);
    }
}