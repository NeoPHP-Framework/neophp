<?php

declare(strict_types=1);

namespace NeoPHP\Component\Http\Request;

use NeoPHP\Component\Http\Exception\HttpException;

class UploadedFile
{
    public const ERRORS = [
        UPLOAD_ERR_OK => 'The file was uploaded successfully.',
        UPLOAD_ERR_INI_SIZE => 'The file exceeds the upload_max_filesize directive.',
        UPLOAD_ERR_FORM_SIZE => 'The file exceeds the MAX_FILE_SIZE directive of the form.',
        UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write the file to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
    ];

    public const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/svg+xml' => 'svg',
        'image/x-icon' => 'ico',
        'application/pdf' => 'pdf',
        'application/zip' => 'zip',
        'application/json' => 'json',
        'application/xml' => 'xml',
        'text/xml' => 'xml',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'audio/mpeg' => 'mp3',
        'audio/ogg' => 'ogg',
        'audio/wav' => 'wav',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
    ];

    public const FORBIDDEN_EXTENSIONS = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'cgi', 'pl', 'py', 'sh', 'asp', 'aspx', 'jsp', 'htaccess', 'htpasswd', 'ini', 'shtml'];

    protected bool $moved = false;

    public function __construct(
        protected string $path,
        protected string $originalName,
        protected ?string $mimeType = null,
        protected int $error = UPLOAD_ERR_OK,
        protected int $size = 0,
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getClientOriginalName(): string
    {
        return $this->originalName;
    }

    public function getClientOriginalExtension(): string
    {
        return strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION));
    }

    public function getClientMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function getMimeType(): ?string
    {
        if (!function_exists('finfo_open') || !is_file($this->path)) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo === false ? false : finfo_file($finfo, $this->path);

        return is_string($mimeType) ? $mimeType : null;
    }

    public function guessExtension(): ?string
    {
        $mimeType = $this->getMimeType();

        return $mimeType === null ? null : (self::EXTENSIONS[$mimeType] ?? null);
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getErrorMessage(): string
    {
        return self::ERRORS[$this->error] ?? 'Unknown upload error.';
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && !$this->moved && (PHP_SAPI === 'cli' || is_uploaded_file($this->path));
    }

    public function move(string $directory, ?string $name = null): string
    {
        if (!$this->isValid()) {
            throw new HttpException(400, 'The file "{name}" cannot be moved: {error}', [], ['name' => $this->originalName, 'error' => $this->getErrorMessage()]);
        }

        $name ??= bin2hex(random_bytes(16)) . (($extension = $this->guessExtension()) !== null ? '.' . $extension : '');

        if ($name === '' || $name !== basename($name) || str_starts_with($name, '.')) {
            throw new HttpException(400, 'The file name "{name}" is not valid.', [], ['name' => $name]);
        }

        if (in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::FORBIDDEN_EXTENSIONS, true)) {
            throw new HttpException(400, 'The extension of the file "{name}" is not allowed.', [], ['name' => $name]);
        }

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new HttpException(500, 'Unable to create the directory "{directory}".', [], ['directory' => $directory]);
        }

        $target = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;
        $moved = PHP_SAPI === 'cli' ? rename($this->path, $target) : move_uploaded_file($this->path, $target);

        if (!$moved) {
            throw new HttpException(500, 'Unable to move the file "{name}" to "{target}".', [], ['name' => $this->originalName, 'target' => $target]);
        }

        $this->moved = true;

        return $target;
    }
}