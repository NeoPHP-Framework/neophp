<?php

declare(strict_types=1);

namespace NeoPHP\Component\Upload;

use NeoPHP\Component\Http\Exception\HttpException;
use NeoPHP\Component\Http\Request\UploadedFile;
use NeoPHP\Component\Upload\Exception\UploadException;

interface UploadManagerInterface
{
    /**
     * Stores an uploaded file in the upload directory, with a random name and the extension of the MIME type detected from its content.
     *
     * @param UploadedFile $file The uploaded file
     * @param string $directory Sub-directory of the upload directory
     * @param array<string, mixed> $options Options replacing the configuration: max_size (bytes), mime_types (MIME type => extension), name (file name without extension)
     * @return string The path of the stored file, relative to the upload directory
     * @throws UploadException When the upload failed, the file is too large, its type or extension is not allowed, the path is not allowed or the fileinfo extension is missing
     * @throws HttpException When the file cannot be moved to the upload directory
     */
    public function store(UploadedFile $file, string $directory = '', array $options = []): string;

    /**
     * Deletes a stored file; an empty path, an external URL or a missing file is ignored.
     *
     * @param string|null $path Path relative to the upload directory, or URL of the file
     * @return bool True when the file was deleted
     * @throws UploadException When the path is not allowed or the file cannot be deleted
     */
    public function delete(?string $path): bool;

    /**
     * Tells whether a stored file exists.
     *
     * @param string|null $path Path relative to the upload directory, or URL of the file
     * @return bool True when the file exists
     * @throws UploadException When the path is not allowed
     */
    public function exists(?string $path): bool;

    /**
     * Returns the public URL of a stored file, with the sub-directory of the application; an external URL is returned unchanged.
     *
     * @param string|null $path Path relative to the upload directory
     * @param string|null $default Value returned when the path is empty
     * @return string|null The URL, or the default value
     * @throws UploadException When the path is not allowed
     */
    public function url(?string $path, ?string $default = null): ?string;

    /**
     * Returns the absolute path of a stored file.
     *
     * @param string $path Path relative to the upload directory, or URL of the file
     * @return string The absolute path
     * @throws UploadException When the path contains ".." or a null byte
     */
    public function path(string $path): string;

    /**
     * Returns the upload directory.
     *
     * @return string The absolute upload directory (public/uploads by default)
     */
    public function getUploadPath(): string;

    /**
     * Returns the URL prefix of the stored files.
     *
     * @return string The public URL (/uploads by default)
     */
    public function getPublicUrl(): string;
}