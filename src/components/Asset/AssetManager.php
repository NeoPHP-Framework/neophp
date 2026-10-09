<?php

declare(strict_types=1);

namespace NeoPHP\Component\Asset;

use Closure;
use FilesystemIterator;
use NeoPHP\Component\Asset\Compiler\CompilerInterface;
use NeoPHP\Component\Asset\Compiler\CssCompiler;
use NeoPHP\Component\Asset\Compiler\JsCompiler;
use NeoPHP\Component\Asset\Exception\AssetException;
use NeoPHP\Component\Asset\Manifest\Manifest;
use NeoPHP\Component\Asset\Provider\AssetProvider;
use NeoPHP\Component\Kernel\Attribute\Component;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[Component(provider: AssetProvider::class)]
final class AssetManager implements AssetManagerInterface
{
    protected string $sourcePath = '';

    protected string $buildPath = '';

    protected string $publicUrl = '/builds';

    protected bool $autoCompile = false;

    protected bool $minify = false;

    protected string $hashAlgorithm = 'xxh128';

    protected int $hashLength = 8;

    protected ?Manifest $manifest = null;

    protected array $compilers = [];

    protected array $resolved = [];

    protected Closure|string|null $basePath = null;

    protected array $compiling = [];

    protected array $sourceFiles = [];

    public function __construct(
        string $sourcePath,
        string $buildPath,
        string $publicUrl = '/builds',
        bool $autoCompile = false,
        string $hashAlgorithm = 'xxh128',
        int $hashLength = 8,
        ?array $compilers = null,
    ) {
        if (!in_array($hashAlgorithm, hash_algos(), true)) {
            throw new AssetException('The hash algorithm "{algorithm}" is not supported.', 0, null, ['algorithm' => $hashAlgorithm]);
        }

        $this->sourcePath = rtrim(str_replace('\\', '/', $sourcePath), '/');
        $this->buildPath = rtrim(str_replace('\\', '/', $buildPath), '/');
        $this->publicUrl = rtrim($publicUrl, '/');
        $this->autoCompile = $autoCompile;
        $this->hashAlgorithm = $hashAlgorithm;
        $this->hashLength = max(4, $hashLength);

        foreach ($compilers ?? [new CssCompiler(), new JsCompiler()] as $compiler) {
            $this->addCompiler($compiler);
        }
    }

    public static function fromConfig(array $config, array $defaults = []): static
    {
        $config = array_replace($defaults, $config);
        $hash = (array) ($config['hash'] ?? []);

        return new static(
            (string) ($config['source_path'] ?? 'assets'),
            (string) ($config['build_path'] ?? 'public/builds'),
            (string) ($config['public_url'] ?? '/builds'),
            (bool) ($config['auto_compile'] ?? false),
            (string) ($hash['algorithm'] ?? 'xxh128'),
            (int) ($hash['length'] ?? 8),
        );
    }

    public function url(string $path): string
    {
        if ($this->isExternal($path)) {
            return $path;
        }

        $url = $this->resolve($this->normalize($path));
        $this->getManifest()->save();

        return $this->isExternal($url) ? $url : $this->getBasePath() . $url;
    }

    public function setBasePath(Closure|string|null $basePath): static
    {
        $this->basePath = $basePath;

        return $this;
    }

    public function getBasePath(): string
    {
        $basePath = $this->basePath instanceof Closure ? ($this->basePath)() : $this->basePath;

        return rtrim(trim((string) $basePath), '/');
    }

    public function compile(string $path): string
    {
        $path = $this->normalize($path);
        unset($this->resolved[$path]);

        $url = $this->build($path);
        $this->getManifest()->save();

        return $url;
    }

    public function reload(bool $minify = false): array
    {
        $this->clear();
        $this->minify = $minify;
        $built = [];

        try {
            foreach ($this->sources() as $path) {
                $built[$path] = $this->resolve($path);
            }
        } finally {
            $this->minify = false;
        }

        $this->getManifest()->save();

        return $built;
    }

    public function clear(): void
    {
        $this->assertSafeBuildPath();

        if (is_dir($this->buildPath)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->buildPath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || str_starts_with($file->getFilename(), '.')) {
                    continue;
                }

                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
        }

        $this->resolved = [];
        $this->getManifest()->clear()->save();
    }

    public function addCompiler(CompilerInterface $compiler): static
    {
        $this->compilers[] = $compiler;

        return $this;
    }

    public function setSourceFile(string $path, ?string $file): static
    {
        $path = $this->normalize($path);

        if ($file === null) {
            unset($this->sourceFiles[$path]);
        } else {
            $this->sourceFiles[$path] = $file;
        }

        unset($this->resolved[$path]);

        return $this;
    }

    public function getSourceFile(string $path): string
    {
        $path = $this->normalize($path);
        $file = $this->sourceFiles[$path] ?? null;

        return $file !== null && is_file($file) ? $file : $this->sourcePath . '/' . $path;
    }

    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }

    public function getBuildPath(): string
    {
        return $this->buildPath;
    }

    public function getManifest(): Manifest
    {
        return $this->manifest ??= new Manifest($this->buildPath . '/manifest.json');
    }

    protected function resolve(string $path): string
    {
        if (isset($this->resolved[$path])) {
            return $this->resolved[$path];
        }

        if (!$this->autoCompile) {
            $url = $this->getManifest()->get($path);

            if ($url !== null) {
                return $this->resolved[$path] = $url;
            }
        }

        return $this->build($path);
    }

    protected function build(string $path): string
    {
        $source = $this->getSourceFile($path);

        if (!is_file($source)) {
            throw new AssetException('The asset "{path}" does not exist in "{directory}".', 0, null, [
                'path' => $path,
                'directory' => $this->sourcePath,
            ]);
        }

        if (isset($this->compiling[$path])) {
            throw new AssetException('Circular reference detected while compiling the asset "{path}".', 0, null, ['path' => $path]);
        }

        $this->compiling[$path] = true;

        try {
            $content = (string) file_get_contents($source);
            $compiler = $this->compilerFor($path);

            if ($compiler !== null) {
                $content = $compiler->compile($content, $path, fn (string $dependency): ?string => $this->resolveDependency($dependency), $this->minify);
            }
        } finally {
            unset($this->compiling[$path]);
        }

        $target = $this->targetName($path, $content);
        $this->write($this->buildPath . '/' . $target, $content);

        $url = $this->publicUrl . '/' . $target;
        $previous = $this->getManifest()->get($path);

        if ($previous !== null && $previous !== $url) {
            $this->removeBuild($previous);
        }

        $this->getManifest()->set($path, $url);

        return $this->resolved[$path] = $url;
    }

    protected function resolveDependency(string $path): ?string
    {
        return is_file($this->sourcePath . '/' . $path) ? $this->resolve($path) : null;
    }

    protected function compilerFor(string $path): ?CompilerInterface
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        foreach ($this->compilers as $compiler) {
            if ($compiler->supports($extension)) {
                return $compiler;
            }
        }

        return null;
    }

    protected function targetName(string $path, string $content): string
    {
        $info = pathinfo($path);
        $directory = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'] . '/';
        $hash = substr(hash($this->hashAlgorithm, $content), 0, $this->hashLength);
        $extension = isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '';

        return $directory . $info['filename'] . '-' . $hash . $extension;
    }

    protected function write(string $file, string $content): void
    {
        if (is_file($file)) {
            return;
        }

        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new AssetException('Unable to create the directory "{directory}".', 0, null, ['directory' => $directory]);
        }

        $temporary = $file . '.' . uniqid('', true) . '.tmp';

        if (file_put_contents($temporary, $content) === false || !rename($temporary, $file)) {
            @unlink($temporary);

            throw new AssetException('Unable to write the asset "{file}".', 0, null, ['file' => $file]);
        }
    }

    protected function removeBuild(string $url): void
    {
        if (!str_starts_with($url, $this->publicUrl . '/')) {
            return;
        }

        $relative = substr($url, strlen($this->publicUrl) + 1);

        if (str_contains('/' . $relative . '/', '/../')) {
            return;
        }

        $file = $this->buildPath . '/' . $relative;

        if (is_file($file)) {
            @unlink($file);
        }
    }

    protected function sources(): array
    {
        if (!is_dir($this->sourcePath)) {
            return [];
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->sourcePath, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($this->sourcePath)), '/');

            if (preg_match('#(^|/)\.#', $relative) === 1) {
                continue;
            }

            $paths[] = $relative;
        }

        sort($paths);

        return $paths;
    }

    protected function normalize(string $path): string
    {
        $parts = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($parts === []) {
                    throw new AssetException('The asset path "{path}" is outside the assets directory.', 0, null, ['path' => $path]);
                }

                array_pop($parts);
                continue;
            }

            $parts[] = $segment;
        }

        if ($parts === []) {
            throw new AssetException('The asset path "{path}" is not valid.', 0, null, ['path' => $path]);
        }

        return implode('/', $parts);
    }

    protected function isExternal(string $path): bool
    {
        return preg_match('#^([a-z][a-z0-9+.-]*:)?//#i', $path) === 1 || str_starts_with($path, 'data:');
    }

    protected function assertSafeBuildPath(): void
    {
        $build = rtrim(str_replace('\\', '/', (string) (realpath($this->buildPath) ?: $this->buildPath)), '/') . '/';
        $source = rtrim(str_replace('\\', '/', (string) (realpath($this->sourcePath) ?: $this->sourcePath)), '/') . '/';

        if ($build === '/' || str_starts_with($source, $build)) {
            throw new AssetException('The build directory "{build}" must not contain the assets directory "{source}".', 0, null, [
                'build' => $this->buildPath,
                'source' => $this->sourcePath,
            ]);
        }
    }
}