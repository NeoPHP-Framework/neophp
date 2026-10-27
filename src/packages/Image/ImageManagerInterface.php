<?php

declare(strict_types=1);

namespace NeoPHP\Package\Image;

use NeoPHP\Package\Image\Exception\ImageException;
use NeoPHP\Package\Image\Model\Image;

interface ImageManagerInterface
{
    public const MODES = ['fit', 'cover', 'resize'];

    /**
     * Loads an image.
     *
     * @param string $path Absolute path, or path relative to the upload directory (public/uploads)
     * @return Image The image, to transform and save
     * @throws ImageException When the path is invalid, the file does not exist or is not a supported image
     */
    public function open(string $path): Image;

    /**
     * Loads an image from its content (an upload read in memory, an image downloaded with the HttpClient).
     *
     * @param string $data The bytes of the image
     * @return Image The image
     * @throws ImageException When the data is not a supported image
     */
    public function read(string $data): Image;

    /**
     * Applies a preset to an image and returns the path of the derivative, generated once and kept in the cache directory.
     * The derivative is generated again when the source is more recent.
     *
     * @param string $path Absolute path, or path relative to the upload directory
     * @param string $preset Name of a preset of config/packages/image.yaml
     * @return string The absolute path of the derivative
     * @throws ImageException When the preset is unknown, the source is invalid or the derivative cannot be written
     */
    public function thumbnail(string $path, string $preset): string;

    /**
     * Returns the public URL of the derivative of an image, generated when needed.
     *
     * @param string|null $path Path relative to the upload directory, or URL of the upload
     * @param string $preset Name of a preset
     * @param string|null $default Value returned when the path is empty or the source does not exist
     * @return string|null The URL of the derivative, or the default value
     * @throws ImageException When the preset is unknown or the derivative cannot be written
     */
    public function url(?string $path, string $preset, ?string $default = null): ?string;

    /**
     * Prepares an original right after an upload: turns it upright (EXIF orientation), removes its metadata (GPS position, camera),
     * shrinks it to the maximum size and converts it when a format is given. The file is replaced.
     *
     * @param string $path Absolute path, or path relative to the upload directory
     * @param array<string, mixed> $options Options replacing the "optimize" configuration: max_width, max_height, format, quality
     * @return string The path of the optimized image, relative to the upload directory when the given path was; its extension changes with the format
     * @throws ImageException When the source is invalid or the image cannot be written
     */
    public function optimize(string $path, array $options = []): string;

    /**
     * Deletes the derivatives of an image (after the image is replaced or deleted).
     *
     * @param string $path Absolute path, or path relative to the upload directory
     * @return int The number of deleted files
     */
    public function forget(string $path): int;

    /**
     * Deletes the cached derivatives.
     *
     * @param string|null $preset Only the derivatives of this preset
     * @return int The number of deleted files
     */
    public function clear(?string $preset = null): int;

    /**
     * Returns the normalized presets of the configuration.
     *
     * @return array<string, array{width: int|null, height: int|null, mode: string, position: string, format: string|null, quality: int, upscale: bool}>
     */
    public function getPresets(): array;

    /**
     * Returns the directory of the derivatives.
     *
     * @return string The absolute cache directory (public/uploads/cache by default)
     */
    public function getCachePath(): string;
}