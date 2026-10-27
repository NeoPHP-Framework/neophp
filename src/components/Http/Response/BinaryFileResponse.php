<?php

declare(strict_types=1);

namespace NeoPHP\Component\Http\Response;

use NeoPHP\Component\Http\Exception\HttpException;
use NeoPHP\Component\Http\Exception\NotFoundHttpException;
use NeoPHP\Component\Http\Request\Request;
use SplFileInfo;

class BinaryFileResponse extends Response
{
    public const DISPOSITION_ATTACHMENT = 'attachment';

    public const DISPOSITION_INLINE = 'inline';

    public const CHUNK_SIZE = 65536;

    public const MIME_TYPES = [
        'txt' => 'text/plain', 'csv' => 'text/csv', 'html' => 'text/html', 'css' => 'text/css', 'js' => 'text/javascript',
        'json' => 'application/json', 'xml' => 'application/xml', 'pdf' => 'application/pdf', 'zip' => 'application/zip',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'avif' => 'image/avif', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'woff' => 'font/woff', 'woff2' => 'font/woff2',
    ];

    protected SplFileInfo $file;

    protected int $offset = 0;

    protected int $length = -1;

    protected bool $deleteFileAfterSend = false;

    protected bool $headOnly = false;

    protected bool $sent = false;

    /**
     * @param string|SplFileInfo $file The file to send
     * @param int $status HTTP status code
     * @param array<string, mixed> $headers HTTP headers, by name (a Content-Type given here is kept)
     * @param string|null $disposition "attachment" (download), "inline" (displayed by the browser) or null (no Content-Disposition header)
     * @param string|null $filename Name proposed to the user, the name of the file by default
     * @param bool $autoEtag Adds an ETag computed from the path, the size and the modification time of the file
     * @param bool $autoLastModified Adds a Last-Modified header from the modification time of the file
     * @throws NotFoundHttpException When the file does not exist or is not readable
     * @throws HttpException When the disposition is invalid
     */
    public function __construct(
        string|SplFileInfo $file,
        int $status = 200,
        array $headers = [],
        ?string $disposition = null,
        ?string $filename = null,
        bool $autoEtag = true,
        bool $autoLastModified = true,
    ) {
        parent::__construct('', $status, $headers);

        $this->setFile($file, $disposition, $filename, $autoEtag, $autoLastModified);
    }

    /**
     * @throws NotFoundHttpException When the file does not exist or is not readable
     * @throws HttpException When the disposition is invalid
     */
    public function setFile(string|SplFileInfo $file, ?string $disposition = null, ?string $filename = null, bool $autoEtag = true, bool $autoLastModified = true): static
    {
        $file = $file instanceof SplFileInfo ? $file : new SplFileInfo($file);

        if (!$file->isFile() || !$file->isReadable()) {
            throw new NotFoundHttpException('The file "{file}" does not exist or is not readable.', ['file' => $file->getPathname()]);
        }

        $this->file = $file;

        if (!$this->headers->has('Content-Type')) {
            $this->headers->set('Content-Type', static::guessMimeType($file));
        }

        if ($disposition !== null) {
            $this->setContentDisposition($disposition, $filename ?? $file->getFilename());
        }

        if ($autoEtag) {
            $this->headers->set('ETag', '"' . hash('xxh128', $file->getRealPath() . '|' . $file->getSize() . '|' . $file->getMTime()) . '"');
        }

        if ($autoLastModified) {
            $this->headers->set('Last-Modified', gmdate('D, d M Y H:i:s', (int) $file->getMTime()) . ' GMT');
        }

        return $this;
    }

    public function getFile(): SplFileInfo
    {
        return $this->file;
    }

    /**
     * Sets the Content-Disposition header, with an ASCII name for the old browsers and the UTF-8 name (RFC 6266).
     *
     * @param string $disposition "attachment" or "inline"
     * @param string $filename Name proposed to the user
     * @param string|null $fallback ASCII name, derived from the name when null
     * @throws HttpException When the disposition is invalid or a name contains a slash, a backslash or a control character
     */
    public function setContentDisposition(string $disposition, string $filename = '', ?string $fallback = null): static
    {
        $disposition = strtolower($disposition);

        if (!in_array($disposition, [self::DISPOSITION_ATTACHMENT, self::DISPOSITION_INLINE], true)) {
            throw new HttpException(500, 'The disposition "{disposition}" is invalid: use "attachment" or "inline".', [], ['disposition' => $disposition]);
        }

        $filename = $filename === '' ? $this->file->getFilename() : $filename;

        if (preg_match('/[\/\\\\\x00-\x1F\x7F]/', $filename) === 1) {
            throw new HttpException(500, 'The file name "{filename}" cannot contain a slash, a backslash or a control character.', [], ['filename' => $filename]);
        }

        $fallback ??= static::asciiFilename($filename);
        $header = sprintf('%s; filename="%s"', $disposition, str_replace(['\\', '"'], ['\\\\', '\\"'], $fallback));

        if ($fallback !== $filename) {
            $header .= "; filename*=UTF-8''" . rawurlencode($filename);
        }

        $this->headers->set('Content-Disposition', $header);

        return $this;
    }

    /**
     * Deletes the file once it is sent: temporary exports, generated archives.
     */
    public function deleteFileAfterSend(bool $delete = true): static
    {
        $this->deleteFileAfterSend = $delete;

        return $this;
    }

    /**
     * The body of a file response is read from the disk while it is sent.
     */
    public function getContent(): string
    {
        return '';
    }

    /**
     * @throws HttpException When a non-empty content is given: the body is the file
     */
    public function setContent(string $content): static
    {
        if ($content !== '') {
            throw new HttpException(500, 'The content of a file response cannot be set: it is the file.');
        }

        return $this;
    }

    /**
     * Answers the conditional requests (304 Not Modified) and the byte ranges (206 Partial Content, 416 Range Not Satisfiable).
     */
    public function prepare(Request $request): static
    {
        $this->headOnly = $request->isMethod('HEAD');
        $size = (int) $this->file->getSize();
        $this->offset = 0;
        $this->length = $size;
        $this->headers->set('Accept-Ranges', 'bytes');

        if ($this->isSuccessful() && $this->isNotModified($request)) {
            $this->setStatusCode(304);
            $this->headers->remove('Content-Type');
            $this->headers->remove('Content-Length');
            $this->headers->remove('Content-Disposition');

            return $this;
        }

        if ($this->statusCode === 200 && $request->isMethod('GET') && $request->headers->has('Range') && $this->rangeApplies($request)) {
            $range = static::parseRange((string) $request->headers->get('Range'), $size);

            if ($range === false) {
                $this->setStatusCode(416);
                $this->headers->set('Content-Range', 'bytes */' . $size);
                $this->headers->remove('Content-Length');
                $this->length = 0;

                return $this;
            }

            if ($range !== null && ($range[0] > 0 || $range[1] < $size - 1)) {
                [$this->offset, $end] = $range;
                $this->length = $end - $this->offset + 1;
                $this->setStatusCode(206);
                $this->headers->set('Content-Range', sprintf('bytes %d-%d/%d', $this->offset, $end, $size));
            }
        }

        $this->headers->set('Content-Length', (string) $this->length);

        return $this;
    }

    /**
     * Sends the file (or the requested range) by chunks, without loading it in memory.
     *
     * @throws HttpException When the file cannot be opened
     */
    public function sendContent(): static
    {
        if ($this->sent) {
            return $this;
        }

        $this->sent = true;

        try {
            if ($this->headOnly || $this->isEmpty() || !$this->isSuccessful() || $this->length === 0) {
                return $this;
            }

            $handle = @fopen($this->file->getPathname(), 'rb');

            if ($handle === false) {
                throw new HttpException(500, 'The file "{file}" cannot be opened.', [], ['file' => $this->file->getPathname()]);
            }

            try {
                if ($this->offset > 0) {
                    fseek($handle, $this->offset);
                }

                $remaining = $this->length < 0 ? PHP_INT_MAX : $this->length;

                while ($remaining > 0 && !feof($handle) && connection_aborted() === 0) {
                    $chunk = fread($handle, (int) min(static::CHUNK_SIZE, $remaining));

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    echo $chunk;
                    $remaining -= strlen($chunk);
                    StreamedResponse::flushOutput();
                }
            } finally {
                fclose($handle);
            }
        } finally {
            if ($this->deleteFileAfterSend && is_file($this->file->getPathname())) {
                @unlink($this->file->getPathname());
            }
        }

        return $this;
    }

    /**
     * Returns the type of a file: detected from its content with ext-fileinfo, else from its extension.
     */
    public static function guessMimeType(SplFileInfo $file): string
    {
        if (function_exists('mime_content_type')) {
            $type = @mime_content_type($file->getPathname());

            if (is_string($type) && $type !== '' && $type !== 'application/octet-stream' && !($type === 'text/plain' && isset(self::MIME_TYPES[strtolower($file->getExtension())]))) {
                return $type;
            }
        }

        return self::MIME_TYPES[strtolower($file->getExtension())] ?? 'application/octet-stream';
    }

    /**
     * Parses a "bytes=" Range header with a single range.
     *
     * @return array{0: int, 1: int}|false|null The first and the last byte, false when the range cannot be satisfied, null when the header is ignored (invalid or several ranges)
     */
    public static function parseRange(string $header, int $size): array|false|null
    {
        if (preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i', $header, $matches) !== 1 || ($matches[1] === '' && $matches[2] === '')) {
            return null;
        }

        if ($matches[1] === '') {
            $suffix = (int) $matches[2];

            return $suffix === 0 || $size === 0 ? false : [max(0, $size - $suffix), $size - 1];
        }

        $start = (int) $matches[1];
        $end = $matches[2] === '' ? $size - 1 : min((int) $matches[2], $size - 1);

        if ($start >= $size || $start > $end) {
            return false;
        }

        return [$start, $end];
    }

    protected function isNotModified(Request $request): bool
    {
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return false;
        }

        $ifNoneMatch = $request->headers->get('If-None-Match');
        $etag = $this->headers->get('ETag');

        if ($ifNoneMatch !== null) {
            if ($etag === null) {
                return false;
            }

            $tags = array_map(static fn (string $tag): string => preg_replace('/^W\//', '', trim($tag)) ?? '', explode(',', $ifNoneMatch));

            return in_array('*', $tags, true) || in_array($etag, $tags, true);
        }

        $ifModifiedSince = $request->headers->get('If-Modified-Since');
        $lastModified = $this->headers->get('Last-Modified');

        if ($ifModifiedSince === null || $lastModified === null) {
            return false;
        }

        $since = strtotime($ifModifiedSince);
        $modified = strtotime($lastModified);

        return $since !== false && $modified !== false && $modified <= $since;
    }

    protected function rangeApplies(Request $request): bool
    {
        $ifRange = $request->headers->get('If-Range');

        if ($ifRange === null) {
            return true;
        }

        if ($ifRange === $this->headers->get('ETag')) {
            return true;
        }

        $lastModified = $this->headers->get('Last-Modified');

        return $lastModified !== null && $ifRange === $lastModified;
    }

    protected static function asciiFilename(string $filename): string
    {
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename) : false;
        $ascii = is_string($ascii) ? $ascii : $filename;
        $ascii = (string) preg_replace('/[^\x20-\x7E]/', '_', $ascii);

        return str_replace('%', '_', $ascii);
    }
}