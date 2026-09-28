<?php

declare(strict_types=1);

namespace NeoPHP\Package\Tailwind\Contract;

use NeoPHP\Package\Tailwind\Exception\TailwindException;

abstract class AbstractTailwind implements TailwindInterface
{
    public const RELEASES_URL = 'https://github.com/tailwindlabs/tailwindcss/releases';

    public const DIRECTORY = 'var/tailwind';

    public const CONFIG_FILE = 'config/packages/tailwind.yaml';

    public const IMPORT = '@import "tailwindcss";';

    protected string $rootPath;

    protected string $sourcePath;

    protected ?string $input = null;

    protected string $version = self::DEFAULT_VERSION;

    protected ?string $binary = null;

    public function getInput(): ?string
    {
        return $this->input;
    }

    public function setInput(string $input): static
    {
        $this->input = self::normalizeInput($input);

        return $this;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getBinary(): string
    {
        if ($this->binary !== null && $this->binary !== '') {
            return $this->absolute($this->binary);
        }

        return $this->directory() . '/tailwindcss' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    }

    public function isInstalled(): bool
    {
        return is_file($this->getBinary());
    }

    public function getInstalledVersion(): ?string
    {
        $file = $this->directory() . '/VERSION';

        return is_file($file) ? trim((string) file_get_contents($file)) : null;
    }

    public function getPlatform(): string
    {
        $machine = strtolower(php_uname('m'));
        $arch = match (true) {
            in_array($machine, ['x86_64', 'amd64', 'x64'], true) => 'x64',
            in_array($machine, ['arm64', 'aarch64'], true) => 'arm64',
            default => throw new TailwindException('The processor architecture "{arch}" is not supported by the Tailwind standalone CLI: set "binary" in {config}.', 0, null, ['arch' => $machine, 'config' => self::CONFIG_FILE]),
        };

        return match (PHP_OS_FAMILY) {
            'Windows' => 'tailwindcss-windows-' . $arch . '.exe',
            'Darwin' => 'tailwindcss-macos-' . $arch,
            'Linux' => 'tailwindcss-linux-' . $arch . (is_file('/etc/alpine-release') ? '-musl' : ''),
            default => throw new TailwindException('The operating system "{os}" is not supported by the Tailwind standalone CLI: set "binary" in {config}.', 0, null, ['os' => PHP_OS_FAMILY, 'config' => self::CONFIG_FILE]),
        };
    }

    public function getDownloadUrl(?string $version = null): string
    {
        $version = self::normalizeVersion($version ?? $this->version);

        return $version === self::DEFAULT_VERSION
            ? self::RELEASES_URL . '/latest/download/' . $this->getPlatform()
            : self::RELEASES_URL . '/download/v' . $version . '/' . $this->getPlatform();
    }

    public function install(?string $version = null, bool $force = false): array
    {
        if ($this->binary !== null && $this->binary !== '') {
            throw new TailwindException('The binary is configured ("binary: {binary}" in {config}): nothing to download.', 0, null, ['binary' => $this->binary, 'config' => self::CONFIG_FILE]);
        }

        $binary = $this->getBinary();

        if (!$force && is_file($binary)) {
            return [$binary, $this->getInstalledVersion(), false];
        }

        if (!ini_get('allow_url_fopen') || !in_array('https', stream_get_wrappers(), true)) {
            throw new TailwindException('Unable to download Tailwind: enable allow_url_fopen and the openssl extension, or download {url} manually to {file}.', 0, null, ['url' => $this->getDownloadUrl($version), 'file' => $binary]);
        }

        $url = $this->getDownloadUrl($version);
        [$content, $resolved] = $this->download($url);
        $this->makeDirectory(dirname($binary));
        $temporary = $binary . '.' . uniqid('', true) . '.tmp';

        if (file_put_contents($temporary, $content) === false || !@rename($temporary, $binary)) {
            @unlink($temporary);

            throw new TailwindException('Unable to write the Tailwind binary "{file}".', 0, null, ['file' => $binary]);
        }

        @chmod($binary, 0755);
        $installed = $resolved ?? self::normalizeVersion($version ?? $this->version);
        file_put_contents($this->directory() . '/VERSION', $installed . "\n");

        return [$binary, $installed, true];
    }

    public function getSourceFile(string $input): string
    {
        return $this->sourcePath . '/' . self::normalizeInput($input);
    }

    public function getOutputFile(string $input): string
    {
        return $this->directory() . '/' . self::normalizeInput($input);
    }

    public function initSource(string $input): string
    {
        $file = $this->getSourceFile($input);

        if (!is_file($file)) {
            $this->makeDirectory(dirname($file));
            $this->writeFile($file, self::IMPORT . "\n");

            return 'created';
        }

        $content = (string) file_get_contents($file);

        if (preg_match('/@import\s+[\'"]tailwindcss[\'"]|@tailwind\s/', $content) === 1) {
            return 'unchanged';
        }

        $this->writeFile($file, self::IMPORT . "\n\n" . $content);

        return 'updated';
    }

    public function getCommand(string $input, bool $watch = false, bool $minify = false): array
    {
        $command = [$this->getBinary(), '--input', $this->getSourceFile($input), '--output', $this->getOutputFile($input)];

        if ($watch) {
            $command[] = '--watch=always';
        }

        if ($minify) {
            $command[] = '--minify';
        }

        return $command;
    }

    public function run(string $input, bool $watch = false, bool $minify = false): int
    {
        if (!$this->isInstalled()) {
            throw new TailwindException('Tailwind is not installed: run "php bin/neo tailwind:install".');
        }

        if (!is_file($this->getSourceFile($input))) {
            throw new TailwindException('The Tailwind file "{file}" does not exist: run "php bin/neo tailwind:install" to create it.', 0, null, ['file' => $this->getSourceFile($input)]);
        }

        if (!function_exists('proc_open')) {
            throw new TailwindException('The proc_open() function is disabled: it is required to run Tailwind.');
        }

        $this->makeDirectory(dirname($this->getOutputFile($input)));
        $process = proc_open($this->getCommand($input, $watch, $minify), [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $this->rootPath);

        if (!is_resource($process)) {
            throw new TailwindException('Unable to start Tailwind ({binary}).', 0, null, ['binary' => $this->getBinary()]);
        }

        return proc_close($process);
    }

    public function saveConfig(): string
    {
        $file = $this->rootPath . '/' . self::CONFIG_FILE;
        $values = ['input' => $this->input ?? self::DEFAULT_INPUT, 'version' => $this->version];
        $content = is_file($file) ? (string) file_get_contents($file) : "input: ~\nversion: ~\nbinary: ~\n";

        foreach ($values as $key => $value) {
            $line = $key . ': ' . $value;
            $content = preg_match('/^' . $key . ':.*$/m', $content) === 1
                ? (string) preg_replace('/^' . $key . ':.*$/m', $line, $content, 1)
                : $line . "\n" . $content;
        }

        $this->makeDirectory(dirname($file));
        $this->writeFile($file, rtrim($content) . "\n");

        return $file;
    }

    protected function download(string $url): array
    {
        $context = stream_context_create([
            'http' => ['follow_location' => 1, 'max_redirects' => 10, 'timeout' => 120, 'user_agent' => 'NeoPHP tailwind:install', 'ignore_errors' => true],
        ]);
        $stream = @fopen($url, 'r', false, $context);
        $headers = $stream === false ? [] : (array) stream_get_meta_data($stream)['wrapper_data'];
        $content = $stream === false ? false : stream_get_contents($stream);

        if ($stream !== false) {
            fclose($stream);
        }

        $status = 0;
        $version = null;

        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            } elseif (preg_match('#^Location:.*/download/v([0-9][^/]*)/#i', $header, $matches) === 1) {
                $version = $matches[1];
            }
        }

        if ($content === false || $status !== 200 || $content === '') {
            throw new TailwindException('Unable to download Tailwind from {url} (HTTP {status}): check the version in {config} or download it manually.', 0, null, ['url' => $url, 'status' => $status ?: 'error', 'config' => self::CONFIG_FILE]);
        }

        return [$content, $version];
    }

    protected function directory(): string
    {
        return $this->rootPath . '/' . self::DIRECTORY;
    }

    protected function absolute(string $path): string
    {
        return preg_match('#^([a-zA-Z]:)?[/\\\\]#', $path) === 1 ? $path : $this->rootPath . '/' . $path;
    }

    protected function makeDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new TailwindException('Unable to create the directory "{directory}".', 0, null, ['directory' => $directory]);
        }
    }

    protected function writeFile(string $file, string $content): void
    {
        if (file_put_contents($file, $content) === false) {
            throw new TailwindException('Unable to write the file "{file}".', 0, null, ['file' => $file]);
        }
    }

    protected static function normalizeInput(string $input): string
    {
        $input = trim(str_replace('\\', '/', $input), '/');
        $input = str_starts_with($input, 'assets/') ? substr($input, 7) : $input;

        if ($input === '' || !str_ends_with(strtolower($input), '.css') || str_contains('/' . $input . '/', '/../')) {
            throw new TailwindException('The Tailwind file "{input}" is not valid: give a .css file relative to assets/ (e.g. css/app.css).', 0, null, ['input' => $input]);
        }

        return $input;
    }

    protected static function normalizeVersion(string $version): string
    {
        $version = ltrim(trim($version), 'vV');

        if ($version === '' || strtolower($version) === self::DEFAULT_VERSION) {
            return self::DEFAULT_VERSION;
        }

        if (preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $version) !== 1) {
            throw new TailwindException('The Tailwind version "{version}" is not valid: use "latest" or a version such as 4.1.13.', 0, null, ['version' => $version]);
        }

        return $version;
    }
}