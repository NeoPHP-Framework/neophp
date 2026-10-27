<?php

declare(strict_types=1);

namespace NeoPHP\Package\Image;

use Closure;
use FilesystemIterator;
use NeoPHP\Component\Kernel\Attribute\Package;
use NeoPHP\Component\Upload\Exception\UploadException;
use NeoPHP\Component\Upload\UploadManager;
use NeoPHP\Component\Upload\UploadManagerInterface;
use NeoPHP\Package\Image\Exception\ImageException;
use NeoPHP\Package\Image\Model\Image;
use NeoPHP\Package\Image\Provider\ImageProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[Package(provider: ImageProvider::class, requires: [UploadManager::class])]
final class ImageManager implements ImageManagerInterface
{
    public const CONFIG_FILE = 'config/packages/image.yaml';

    public const DEFAULT_CONFIG = [
        'cache_path' => null,
        'cache_url' => '/media/cache',
        'quality' => Image::DEFAULT_QUALITY,
        'max_pixels' => 50000000,
        'presets' => [],
        'optimize' => [
            'max_width' => 2560,
            'max_height' => 2560,
            'format' => null,
            'quality' => 85,
        ],
    ];

    public const PRESET_DEFAULTS = [
        'width' => null,
        'height' => null,
        'mode' => 'fit',
        'position' => 'center',
        'format' => null,
        'quality' => null,
        'upscale' => false,
    ];

    protected array $config;

    protected array $presets = [];

    protected string $cachePath;

    public function __construct(protected UploadManagerInterface $uploads, array $config = [], ?string $cachePath = null, protected ?Closure $basePath = null)
    {
        $this->config = array_replace(self::DEFAULT_CONFIG, array_filter($config, static fn (mixed $value): bool => $value !== null));
        $this->config['optimize'] = array_replace(self::DEFAULT_CONFIG['optimize'], (array) $this->config['optimize']);
        $this->cachePath = rtrim(str_replace('\\', '/', (string) ($this->config['cache_path'] ?? $cachePath ?? dirname($uploads->getUploadPath()) . '/media/cache')), '/');

        foreach ((array) $this->config['presets'] as $name => $preset) {
            $this->presets[(string) $name] = $this->normalizePreset((string) $name, (array) $preset);
        }
    }

    public function open(string $path): Image
    {
        return Image::fromFile($this->resolve($path), (int) $this->config['max_pixels']);
    }

    public function read(string $data): Image
    {
        return Image::fromString($data, 'string', (int) $this->config['max_pixels']);
    }

    public function thumbnail(string $path, string $preset): string
    {
        $options = $this->preset($preset);
        $source = $this->resolve($path);

        if (!is_file($source)) {
            throw new ImageException('The image "{path}" does not exist.', 0, null, ['path' => $path]);
        }

        $target = $this->cachePath . '/' . $this->cacheKey($source, $preset, $options);

        if (is_file($target) && filemtime($target) >= filemtime($source)) {
            return $target;
        }

        $image = Image::fromFile($source, (int) $this->config['max_pixels'])->autoOrient();

        match ($options['mode']) {
            'cover' => $image->cover((int) ($options['width'] ?? $options['height']), (int) ($options['height'] ?? $options['width']), $options['position'], $options['upscale']),
            'resize' => $image->resize($options['width'], $options['height']),
            default => $image->fit($options['width'], $options['height'], $options['upscale']),
        };

        return $image->save($target, $options['format'] ?? $image->getFormat(), $options['quality']);
    }

    public function url(?string $path, string $preset, ?string $default = null): ?string
    {
        $this->preset($preset);

        if ($path === null || $path === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1) {
            return $path === null || $path === '' ? $default : $path;
        }

        $source = $this->resolve($path);

        if (!is_file($source)) {
            return $default;
        }

        $target = $this->thumbnail($source, $preset);
        $key = substr($target, strlen($this->cachePath) + 1);

        return rtrim($this->basePath !== null ? (string) ($this->basePath)() : '', '/') . '/' . trim((string) $this->config['cache_url'], '/') . '/' . $key;
    }

    public function optimize(string $path, array $options = []): string
    {
        $options = array_replace((array) $this->config['optimize'], $options);
        $source = $this->resolve($path);
        $image = Image::fromFile($source, (int) $this->config['max_pixels']);
        $format = $options['format'] !== null && $options['format'] !== '' ? Image::normalizeFormat((string) $options['format']) : $image->getFormat();

        if ($image->getFormat() === 'gif' && $format === 'gif') {
            return $path;
        }

        $image->autoOrient();

        if ($options['max_width'] !== null || $options['max_height'] !== null) {
            $image->fit($options['max_width'] !== null ? (int) $options['max_width'] : null, $options['max_height'] !== null ? (int) $options['max_height'] : null);
        }

        $sameFormat = Image::formatFromExtension(pathinfo($source, PATHINFO_EXTENSION)) === $format;
        $target = $sameFormat ? $source : $this->withExtension($source, Image::EXTENSIONS[$format]);

        $this->forget($source);
        $image->save($target, $format, (int) ($options['quality'] ?? $this->config['quality']));

        if ($sameFormat) {
            return $path;
        }

        @unlink($source);

        return $this->withExtension($path, Image::EXTENSIONS[$format]);
    }

    protected function withExtension(string $path, string $extension): string
    {
        return (string) preg_replace('#\.[^./\\\\]*$#', '', $path) . '.' . $extension;
    }

    public function forget(string $path): int
    {
        $source = $this->resolve($path);
        $deleted = 0;

        foreach ($this->presets as $name => $options) {
            $file = $this->cachePath . '/' . $this->cacheKey($source, $name, $options);

            if (is_file($file) && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public function clear(?string $preset = null): int
    {
        $directory = $preset === null ? $this->cachePath : $this->cachePath . '/' . $this->preset($preset)['name'];

        if (!is_dir($directory)) {
            return 0;
        }

        $deleted = 0;
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } elseif (@unlink($item->getPathname())) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public function getPresets(): array
    {
        return array_map(static function (array $preset): array {
            unset($preset['name']);

            return $preset;
        }, $this->presets);
    }

    public function getCachePath(): string
    {
        return $this->cachePath;
    }

    /**
     * Returns the absolute path of an image: an absolute path is kept, a URL of the uploads or a relative path is resolved in the upload directory.
     *
     * @throws ImageException When the path leaves the upload directory or is a URL of another site
     */
    protected function resolve(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $publicUrl = rtrim($this->uploads->getPublicUrl(), '/') . '/';

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $normalized) === 1) {
            throw new ImageException('The image "{path}" is a URL: download it first (HttpClient) or give a path of the uploads.', 0, null, ['path' => $path]);
        }

        if (!str_starts_with($normalized, $publicUrl) && $this->isAbsolute($normalized)) {
            return $normalized;
        }

        try {
            return $this->uploads->path($normalized);
        } catch (UploadException $exception) {
            throw new ImageException('The image path "{path}" is not allowed.', 0, $exception, ['path' => $path]);
        }
    }

    protected function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    /**
     * The derivative keeps the path of the image in the upload directory (outside of it, a hash), and the extension of its format.
     */
    protected function cacheKey(string $source, string $preset, array $options): string
    {
        $uploadPath = rtrim(str_replace('\\', '/', $this->uploads->getUploadPath()), '/') . '/';
        $relative = str_starts_with($source, $uploadPath) ? substr($source, strlen($uploadPath)) : '_external/' . substr(hash('sha256', $source), 0, 32) . '.' . pathinfo($source, PATHINFO_EXTENSION);
        $format = $options['format'];

        if ($format !== null && Image::formatFromExtension(pathinfo($relative, PATHINFO_EXTENSION)) !== $format) {
            $relative .= '.' . Image::EXTENSIONS[$format];
        }

        return $preset . '/' . $relative;
    }

    /**
     * @throws ImageException When the preset is unknown
     */
    protected function preset(string $name): array
    {
        if (!isset($this->presets[$name])) {
            throw new ImageException('The image preset "{preset}" does not exist: add it in presets of {config} (available: {presets}).', 0, null, [
                'preset' => $name,
                'config' => self::CONFIG_FILE,
                'presets' => $this->presets === [] ? 'none' : implode(', ', array_keys($this->presets)),
            ]);
        }

        return $this->presets[$name];
    }

    /**
     * @throws ImageException When the name or an option of the preset is invalid
     */
    protected function normalizePreset(string $name, array $preset): array
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
            throw new ImageException('The image preset name "{preset}" is invalid: use letters, digits, "_" and "-".', 0, null, ['preset' => $name]);
        }

        $unknown = array_diff(array_keys($preset), array_keys(self::PRESET_DEFAULTS));

        if ($unknown !== []) {
            throw new ImageException('Unknown option(s) "{options}" in the image preset "{preset}": use {allowed}.', 0, null, [
                'options' => implode('", "', $unknown), 'preset' => $name, 'allowed' => implode(', ', array_keys(self::PRESET_DEFAULTS)),
            ]);
        }

        $preset = array_replace(self::PRESET_DEFAULTS, $preset);
        $mode = strtolower((string) $preset['mode']);
        $width = $preset['width'] !== null ? (int) $preset['width'] : null;
        $height = $preset['height'] !== null ? (int) $preset['height'] : null;

        if (!in_array($mode, self::MODES, true)) {
            throw new ImageException('The mode "{mode}" of the image preset "{preset}" is invalid: use {modes}.', 0, null, ['mode' => $mode, 'preset' => $name, 'modes' => implode(', ', self::MODES)]);
        }

        if (($width === null && $height === null) || ($width !== null && $width < 1) || ($height !== null && $height < 1)) {
            throw new ImageException('The image preset "{preset}" needs a positive width, height or both.', 0, null, ['preset' => $name]);
        }

        return [
            'name' => $name,
            'width' => $width,
            'height' => $height,
            'mode' => $mode,
            'position' => (string) $preset['position'],
            'format' => $preset['format'] !== null && $preset['format'] !== '' ? Image::normalizeFormat((string) $preset['format']) : null,
            'quality' => max(0, min(100, (int) ($preset['quality'] ?? $this->config['quality']))),
            'upscale' => (bool) $preset['upscale'],
        ];
    }
}