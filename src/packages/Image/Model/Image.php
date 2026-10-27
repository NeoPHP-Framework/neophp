<?php

declare(strict_types=1);

namespace NeoPHP\Package\Image\Model;

use GdImage;
use NeoPHP\Package\Image\Exception\ImageException;
use NeoPHP\Package\Image\Exif\OrientationReader;

class Image
{
    public const FORMATS = [
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif'
    ];

    public const EXTENSIONS = [
        'jpeg' => 'jpg',
        'png' => 'png',
        'gif' => 'gif',
        'webp' => 'webp',
        'avif' => 'avif'
    ];

    public const DEFAULT_QUALITY = 82;

    protected GdImage $image;

    public function __construct(GdImage $image, protected string $format = 'png', protected int $orientation = 1)
    {
        $this->setResource($image);
    }

    /**
     * @param int $maxPixels Refuses the images with more pixels (width x height) before decoding them, 0 = no limit
     * @throws ImageException When GD is missing, the file cannot be read, is not a supported image or is too large
     */
    public static function fromFile(string $path, int $maxPixels = 0): static
    {
        static::assertGd();

        if (!is_file($path) || !is_readable($path)) {
            throw new ImageException('The image "{path}" does not exist or is not readable.', 0, null, ['path' => $path]);
        }

        $image = static::fromString((string) file_get_contents($path), $path, $maxPixels);
        $image->orientation = $image->format === 'jpeg' ? OrientationReader::fromFile($path) : 1;

        return $image;
    }

    /**
     * @param int $maxPixels Refuses the images with more pixels (width x height) before decoding them, 0 = no limit
     * @throws ImageException When GD is missing, the data is not a supported image or is too large
     */
    public static function fromString(string $data, string $name = 'string', int $maxPixels = 0): static
    {
        static::assertGd();
        $info = @getimagesizefromstring($data);
        $format = is_array($info) ? static::formatFromMime($info['mime']) : null;

        if (!is_array($info) || $format === null) {
            throw new ImageException('"{name}" is not a supported image (JPEG, PNG, GIF, WebP or AVIF).', 0, null, ['name' => $name]);
        }

        if ($maxPixels > 0 && (int) $info[0] * (int) $info[1] > $maxPixels) {
            throw new ImageException('The image "{name}" is too large ({width}x{height} pixels, {max} pixels allowed).', 0, null, ['name' => $name, 'width' => $info[0], 'height' => $info[1], 'max' => $maxPixels]);
        }

        $gd = @imagecreatefromstring($data);

        if (!$gd instanceof GdImage) {
            throw new ImageException('The image "{name}" cannot be decoded by GD (format {format}).', 0, null, ['name' => $name, 'format' => $format]);
        }

        return new static($gd, $format, $format === 'jpeg' ? OrientationReader::fromString($data) : 1);
    }

    /**
     * Creates an empty image (transparent for the formats that support it).
     */
    public static function canvas(int $width, int $height, string $format = 'png'): static
    {
        static::assertGd();

        return new static(static::blank(max(1, $width), max(1, $height)), static::normalizeFormat($format));
    }

    public function getWidth(): int
    {
        return imagesx($this->image);
    }

    public function getHeight(): int
    {
        return imagesy($this->image);
    }

    /**
     * Returns the format of the source: jpeg, png, gif, webp or avif.
     */
    public function getFormat(): string
    {
        return $this->format;
    }

    public function getMimeType(): string
    {
        return self::FORMATS[$this->format];
    }

    /**
     * Returns the EXIF orientation of the source (1 = upright, 6 = rotated 90° clockwise...), 1 once autoOrient() is applied.
     */
    public function getOrientation(): int
    {
        return $this->orientation;
    }

    public function getResource(): GdImage
    {
        return $this->image;
    }

    /**
     * Turns the photo upright according to its EXIF orientation (photos taken with a phone held sideways).
     */
    public function autoOrient(): static
    {
        match ($this->orientation) {
            2 => $this->flip(true, false),
            3 => $this->rotate(180),
            4 => $this->flip(false, true),
            5 => $this->rotate(90)->flip(true, false),
            6 => $this->rotate(90),
            7 => $this->rotate(-90)->flip(true, false),
            8 => $this->rotate(-90),
            default => null,
        };

        $this->orientation = 1;

        return $this;
    }

    /**
     * Resizes the image to exactly these dimensions (the ratio can change); a null dimension keeps the ratio.
     *
     * @throws ImageException When both dimensions are null or a dimension is not positive
     */
    public function resize(?int $width, ?int $height): static
    {
        [$width, $height] = $this->dimensions($width, $height);

        return $this->resample(0, 0, $this->getWidth(), $this->getHeight(), $width, $height);
    }

    /**
     * Shrinks the image to fit in the box, keeping its ratio: the result is at most $width x $height.
     *
     * @param bool $upscale Also enlarges a smaller image
     * @throws ImageException When both dimensions are null or a dimension is not positive
     */
    public function fit(?int $width, ?int $height, bool $upscale = false): static
    {
        $this->dimensions($width, $height);
        $ratio = min(
            $width === null ? PHP_FLOAT_MAX : $width / $this->getWidth(),
            $height === null ? PHP_FLOAT_MAX : $height / $this->getHeight(),
        );

        if ($ratio >= 1 && !$upscale) {
            return $this;
        }

        return $this->resample(0, 0, $this->getWidth(), $this->getHeight(), max(1, (int) round($this->getWidth() * $ratio)), max(1, (int) round($this->getHeight() * $ratio)));
    }

    /**
     * Fills the box exactly, keeping the ratio and cutting what overflows (square thumbnails, covers).
     *
     * @param string $position Part kept: center, top, bottom, left, right, top-left, top-right, bottom-left, bottom-right
     * @param bool $upscale Also enlarges a smaller image; otherwise the box is reduced to the size of the image
     * @throws ImageException When a dimension is not positive or the position is unknown
     */
    public function cover(int $width, int $height, string $position = 'center', bool $upscale = false): static
    {
        $this->dimensions($width, $height);

        if (!$upscale) {
            $scale = min(1, $this->getWidth() / $width, $this->getHeight() / $height);
            $width = max(1, (int) round($width * $scale));
            $height = max(1, (int) round($height * $scale));
        }

        $ratio = max($width / $this->getWidth(), $height / $this->getHeight());
        $sourceWidth = (int) round($width / $ratio);
        $sourceHeight = (int) round($height / $ratio);
        [$x, $y] = $this->position($position, $this->getWidth() - $sourceWidth, $this->getHeight() - $sourceHeight);

        return $this->resample($x, $y, $sourceWidth, $sourceHeight, $width, $height);
    }

    /**
     * Cuts a rectangle of the image.
     *
     * @throws ImageException When the rectangle is outside the image
     */
    public function crop(int $x, int $y, int $width, int $height): static
    {
        if ($x < 0 || $y < 0 || $width < 1 || $height < 1 || $x + $width > $this->getWidth() || $y + $height > $this->getHeight()) {
            throw new ImageException('The crop {width}x{height}+{x}+{y} is outside the image ({imageWidth}x{imageHeight}).', 0, null, [
                'width' => $width, 'height' => $height, 'x' => $x, 'y' => $y, 'imageWidth' => $this->getWidth(), 'imageHeight' => $this->getHeight(),
            ]);
        }

        return $this->resample($x, $y, $width, $height, $width, $height);
    }

    /**
     * Rotates the image clockwise.
     *
     * @param float $degrees Angle, clockwise (90, 180, -90...)
     * @param string $background Color of the uncovered corners for other angles than multiples of 90 (#RRGGBB, #RRGGBBAA or "transparent")
     */
    public function rotate(float $degrees, string $background = 'transparent'): static
    {
        $degrees = fmod($degrees, 360.0);

        if ($degrees === 0.0) {
            return $this;
        }

        $rotated = imagerotate($this->image, -$degrees, $this->color($this->image, $background));

        if ($rotated === false) {
            throw new ImageException('The image cannot be rotated by {degrees}°.', 0, null, ['degrees' => $degrees]);
        }

        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);
        $this->setResource($rotated);

        return $this;
    }

    public function flip(bool $horizontal = true, bool $vertical = false): static
    {
        $mode = match (true) {
            $horizontal && $vertical => IMG_FLIP_BOTH,
            $horizontal => IMG_FLIP_HORIZONTAL,
            $vertical => IMG_FLIP_VERTICAL,
            default => null,
        };

        if ($mode !== null) {
            imageflip($this->image, $mode);
        }

        return $this;
    }

    /**
     * Converts the image to shades of grey.
     */
    public function greyscale(): static
    {
        imagefilter($this->image, IMG_FILTER_GRAYSCALE);

        return $this;
    }

    /**
     * Returns the encoded image, without metadata.
     *
     * @param string|null $format jpeg (or jpg), png, gif, webp or avif; the format of the source when null
     * @param int $quality 0 to 100 (JPEG, WebP and AVIF; PNG is always lossless)
     * @throws ImageException When the format is not supported by GD on this server
     */
    public function encode(?string $format = null, int $quality = self::DEFAULT_QUALITY): string
    {
        $format = static::normalizeFormat($format ?? $this->format);
        static::assertSupported($format);
        $quality = max(0, min(100, $quality));
        $image = $this->image;

        if ($format === 'jpeg' || $format === 'gif') {
            $image = $this->flatten($format === 'jpeg');
        }

        ob_start();

        $result = match ($format) {
            'jpeg' => imagejpeg($image, null, $quality),
            'png' => imagepng($image, null, 9),
            'gif' => imagegif($image),
            'webp' => imagewebp($image, null, $quality),
            'avif' => imageavif($image, null, $quality),
            default => false,
        };

        $data = (string) ob_get_clean();

        if ($result === false || $data === '') {
            throw new ImageException('The image cannot be encoded as {format}.', 0, null, ['format' => $format]);
        }

        return $data;
    }

    /**
     * Writes the image in a file (directories created), through a temporary file renamed at the end.
     *
     * @param string|null $format The format, guessed from the extension of the path when null
     * @return string The path of the file
     * @throws ImageException When the format is not supported or the file cannot be written
     */
    public function save(string $path, ?string $format = null, int $quality = self::DEFAULT_QUALITY): string
    {
        $format ??= static::formatFromExtension(pathinfo($path, PATHINFO_EXTENSION)) ?? $this->format;
        $data = $this->encode($format, $quality);
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new ImageException('The directory "{directory}" cannot be created.', 0, null, ['directory' => $directory]);
        }

        $temporary = $directory . DIRECTORY_SEPARATOR . '.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($temporary, $data) === false || !@rename($temporary, $path)) {
            @unlink($temporary);

            throw new ImageException('The image "{path}" cannot be written.', 0, null, ['path' => $path]);
        }

        return $path;
    }

    /**
     * Tells whether GD can write this format on this server.
     */
    public static function supports(string $format): bool
    {
        $format = static::normalizeFormat($format, false);

        return match ($format) {
            'jpeg' => function_exists('imagejpeg') && (imagetypes() & IMG_JPG) !== 0,
            'png' => function_exists('imagepng') && (imagetypes() & IMG_PNG) !== 0,
            'gif' => function_exists('imagegif') && (imagetypes() & IMG_GIF) !== 0,
            'webp' => function_exists('imagewebp') && (imagetypes() & IMG_WEBP) !== 0,
            'avif' => function_exists('imageavif') && defined('IMG_AVIF') && (imagetypes() & IMG_AVIF) !== 0,
            default => false,
        };
    }

    /**
     * Returns the format of a name (jpg, jpeg, png, gif, webp, avif), or null.
     */
    public static function formatFromExtension(string $extension): ?string
    {
        $extension = strtolower($extension);

        return $extension === 'jpg' ? 'jpeg' : (isset(self::FORMATS[$extension]) ? $extension : null);
    }

    /**
     * @throws ImageException When the format is unknown and $throw is true
     */
    public static function normalizeFormat(string $format, bool $throw = true): string
    {
        $normalized = static::formatFromExtension($format) ?? static::formatFromMime($format);

        if ($normalized === null && $throw) {
            throw new ImageException('The image format "{format}" is unknown: use {formats}.', 0, null, ['format' => $format, 'formats' => 'jpeg, png, gif, webp or avif']);
        }

        return $normalized ?? $format;
    }

    public function __clone()
    {
        $copy = static::blank($this->getWidth(), $this->getHeight());
        imagecopy($copy, $this->image, 0, 0, 0, 0, $this->getWidth(), $this->getHeight());
        $this->image = $copy;
    }

    protected static function formatFromMime(string $mime): ?string
    {
        $format = array_search(strtolower($mime), self::FORMATS, true);

        return is_string($format) ? $format : null;
    }

    protected static function assertGd(): void
    {
        if (!extension_loaded('gd')) {
            throw new ImageException('The Image package requires the PHP extension gd (ext-gd).');
        }
    }

    protected static function assertSupported(string $format): void
    {
        if (!static::supports($format)) {
            throw new ImageException('The format {format} is not supported by the GD extension of this server.', 0, null, ['format' => $format]);
        }
    }

    protected static function blank(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));

        return $image;
    }

    protected function setResource(GdImage $image): void
    {
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
        $this->image = $image;
    }

    protected function resample(int $x, int $y, int $sourceWidth, int $sourceHeight, int $width, int $height): static
    {
        if ($x === 0 && $y === 0 && $sourceWidth === $width && $sourceHeight === $height && $width === $this->getWidth() && $height === $this->getHeight()) {
            return $this;
        }

        $target = static::blank($width, $height);
        imagecopyresampled($target, $this->image, 0, 0, $x, $y, $width, $height, $sourceWidth, $sourceHeight);
        $this->setResource($target);

        return $this;
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function dimensions(?int $width, ?int $height): array
    {
        if ($width === null && $height === null) {
            throw new ImageException('Give a width, a height or both.');
        }

        if (($width !== null && $width < 1) || ($height !== null && $height < 1)) {
            throw new ImageException('The dimensions must be positive, {width}x{height} given.', 0, null, ['width' => $width ?? 'auto', 'height' => $height ?? 'auto']);
        }

        return [
            $width ?? max(1, (int) round($this->getWidth() * $height / $this->getHeight())),
            $height ?? max(1, (int) round($this->getHeight() * $width / $this->getWidth())),
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function position(string $position, int $freeX, int $freeY): array
    {
        $positions = [
            'center' => [0.5, 0.5], 'top' => [0.5, 0.0], 'bottom' => [0.5, 1.0], 'left' => [0.0, 0.5], 'right' => [1.0, 0.5],
            'top-left' => [0.0, 0.0], 'top-right' => [1.0, 0.0], 'bottom-left' => [0.0, 1.0], 'bottom-right' => [1.0, 1.0],
        ];

        if (!isset($positions[$position])) {
            throw new ImageException('The position "{position}" is unknown: use {positions}.', 0, null, ['position' => $position, 'positions' => implode(', ', array_keys($positions))]);
        }

        return [(int) round($freeX * $positions[$position][0]), (int) round($freeY * $positions[$position][1])];
    }

    protected function color(GdImage $image, string $color): int
    {
        if ($color === 'transparent') {
            return (int) imagecolorallocatealpha($image, 0, 0, 0, 127);
        }

        if (preg_match('/^#?([0-9a-f]{6})([0-9a-f]{2})?$/i', $color, $matches) !== 1) {
            throw new ImageException('The color "{color}" is invalid: use #RRGGBB, #RRGGBBAA or "transparent".', 0, null, ['color' => $color]);
        }

        [$red, $green, $blue] = array_map('hexdec', str_split($matches[1], 2));
        $alpha = isset($matches[2]) ? 127 - (int) round(hexdec($matches[2]) / 255 * 127) : 0;

        return (int) imagecolorallocatealpha($image, (int) $red, (int) $green, (int) $blue, $alpha);
    }

    /**
     * Returns a copy without transparency for the formats that do not support the alpha channel (JPEG on white, GIF keeps one transparent color).
     */
    protected function flatten(bool $white): GdImage
    {
        if (!$white) {
            $copy = imagecreatetruecolor($this->getWidth(), $this->getHeight());
            $transparent = (int) imagecolorallocate($copy, 1, 2, 3);
            imagefill($copy, 0, 0, $transparent);
            imagecolortransparent($copy, $transparent);
            imagealphablending($copy, true);
            imagecopy($copy, $this->image, 0, 0, 0, 0, $this->getWidth(), $this->getHeight());

            return $copy;
        }

        $copy = imagecreatetruecolor($this->getWidth(), $this->getHeight());
        imagefill($copy, 0, 0, (int) imagecolorallocate($copy, 255, 255, 255));
        imagealphablending($copy, true);
        imagecopy($copy, $this->image, 0, 0, 0, 0, $this->getWidth(), $this->getHeight());

        return $copy;
    }
}