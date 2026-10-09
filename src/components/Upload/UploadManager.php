<?php

declare(strict_types=1);

namespace NeoPHP\Component\Upload;

use Closure;
use NeoPHP\Component\Http\Request\UploadedFile;
use NeoPHP\Component\Kernel\Attribute\Component;
use NeoPHP\Component\Upload\Exception\UploadException;
use NeoPHP\Component\Upload\Provider\UploadProvider;

#[Component(provider: UploadProvider::class)]
final class UploadManager implements UploadManagerInterface
{
    public const DEFAULT_MAX_SIZE = 5242880;

    public const DEFAULT_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    public const FORBIDDEN_EXTENSIONS = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phps', 'cgi', 'pl', 'py', 'sh', 'exe', 'bat', 'cmd', 'js', 'html', 'htm', 'svg', 'xml', 'htaccess'];

    protected string $uploadPath;

    protected string $publicUrl;

    protected int $maxSize;

    protected array $mimeTypes;

    protected ?Closure $basePath;

    public function __construct(string $uploadPath, string $publicUrl = '/uploads', int $maxSize = self::DEFAULT_MAX_SIZE, array $mimeTypes = self::DEFAULT_MIME_TYPES, ?Closure $basePath = null)
    {
        $this->uploadPath = rtrim(str_replace('\\', '/', $uploadPath), '/');
        $this->publicUrl = '/' . trim($publicUrl, '/');
        $this->maxSize = $maxSize;
        $this->mimeTypes = $mimeTypes;
        $this->basePath = $basePath;
    }

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

    public function store(UploadedFile $file, string $directory = '', array $options = []): string
    {
        if (!$file->isValid()) {
            throw new UploadException('The file "{name}" cannot be uploaded: {error}', 0, null, ['name' => $file->getClientOriginalName(), 'error' => $file->getErrorMessage()]);
        }

        $maxSize = (int) ($options['max_size'] ?? $this->maxSize);

        if ($maxSize > 0 && $file->getSize() > $maxSize) {
            throw new UploadException('The file "{name}" is too large ({size} bytes, {max} bytes allowed).', 0, null, ['name' => $file->getClientOriginalName(), 'size' => $file->getSize(), 'max' => $maxSize]);
        }

        $mimeTypes = (array) ($options['mime_types'] ?? $this->mimeTypes);
        $mimeType = $this->detectMimeType($file);

        if (!isset($mimeTypes[$mimeType])) {
            throw new UploadException('The type "{type}" of the file "{name}" is not allowed (allowed: {allowed}).', 0, null, ['type' => $mimeType, 'name' => $file->getClientOriginalName(), 'allowed' => implode(', ', array_keys($mimeTypes))]);
        }

        $extension = strtolower((string) $mimeTypes[$mimeType]);

        if ($extension === '' || in_array($extension, self::FORBIDDEN_EXTENSIONS, true)) {
            throw new UploadException('The extension "{extension}" is not allowed.', 0, null, ['extension' => $extension]);
        }

        $directory = $this->normalize($directory);
        $name = isset($options['name']) && is_string($options['name']) && $options['name'] !== '' ? $this->sanitizeName($options['name']) : bin2hex(random_bytes(16));
        $file->move($this->uploadPath . ($directory !== '' ? '/' . $directory : ''), $name . '.' . $extension);

        return ($directory !== '' ? $directory . '/' : '') . $name . '.' . $extension;
    }

    public function delete(?string $path): bool
    {
        if ($path === null || $path === '' || $this->isExternal($path)) {
            return false;
        }

        $file = $this->path($path);

        if (!is_file($file)) {
            return false;
        }

        if (!unlink($file)) {
            throw new UploadException('Unable to delete the file "{file}".', 0, null, ['file' => $file]);
        }

        return true;
    }

    public function exists(?string $path): bool
    {
        if ($path === null || $path === '' || $this->isExternal($path)) {
            return false;
        }

        return is_file($this->path($path));
    }

    public function url(?string $path, ?string $default = null): ?string
    {
        if ($path === null || $path === '') {
            return $default;
        }

        if ($this->isExternal($path)) {
            return $path;
        }

        return rtrim($this->basePath !== null ? (string) ($this->basePath)() : '', '/') . $this->publicUrl . '/' . $this->normalize($path);
    }

    public function path(string $path): string
    {
        $path = $this->normalize($path);

        return $this->uploadPath . ($path !== '' ? '/' . $path : '');
    }

    public function getUploadPath(): string
    {
        return $this->uploadPath;
    }

    public function getPublicUrl(): string
    {
        return $this->publicUrl;
    }

    protected function normalize(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        if ($path === '' || $path === trim($this->publicUrl, '/')) {
            return '';
        }

        if (str_starts_with($path, trim($this->publicUrl, '/') . '/')) {
            $path = substr($path, strlen(trim($this->publicUrl, '/')) + 1);
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..' || str_contains($segment, "\0")) {
                throw new UploadException('The path "{path}" is not allowed.', 0, null, ['path' => $path]);
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    protected function sanitizeName(string $name): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', pathinfo($name, PATHINFO_FILENAME));
        $name = trim($name, '-');

        return $name !== '' ? strtolower($name) : bin2hex(random_bytes(16));
    }

    protected function detectMimeType(UploadedFile $file): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $type = $finfo !== false ? finfo_file($finfo, $file->getPath()) : false;

            if (is_string($type) && $type !== '') {
                return $type;
            }
        }

        throw new UploadException('Unable to detect the type of the file "{name}": the fileinfo extension is required.', 0, null, ['name' => $file->getClientOriginalName()]);
    }

    protected function isExternal(string $path): bool
    {
        return preg_match('#^([a-z][a-z0-9+.-]*:)?//#i', $path) === 1 || str_starts_with($path, 'data:');
    }
}