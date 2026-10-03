<?php

declare(strict_types=1);

namespace NeoPHP\Process\Installer\Contract;

use FilesystemIterator;
use NeoPHP\Process\Installer\Exception\InstallerException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

abstract class AbstractInstaller implements InstallerInterface
{
    public const STUB_EXTENSION = '.stub';

    public const EXECUTABLES = [
        'bin/neo',
    ];

    public const MERGEABLE = [
        '.env',
    ];

    public const DIRECTORIES = [
        'assets',
        'config/packages',
        'migrations',
        'public/builds',
        'src/Command',
        'src/Entity',
        'src/Event',
        'src/Form',
        'src/Listener',
        'src/Message',
        'src/MessageHandler',
        'src/Middleware',
        'src/Repository',
        'src/Security',
        'src/Service',
        'src/Task',
        'tests',
        'translations',
        'var/cache',
        'var/log',
        'var/sessions',
    ];

    public function __construct(protected string $skeletonDir)
    {
    }

    public function getSkeletonDir(): string
    {
        return $this->skeletonDir;
    }

    public function getDirectories(): array
    {
        return static::DIRECTORIES;
    }

    public function getFiles(): array
    {
        if (!is_dir($this->skeletonDir)) {
            throw new InstallerException('The skeleton directory "{directory}" does not exist.', 0, null, ['directory' => $this->skeletonDir]);
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->skeletonDir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || !str_ends_with($file->getFilename(), static::STUB_EXTENSION)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->skeletonDir) + 1));
            $files[substr($relative, 0, -strlen(static::STUB_EXTENSION))] = $file->getPathname();
        }

        ksort($files);

        return $files;
    }

    public function install(string $projectDir, bool $force = false): array
    {
        $projectDir = rtrim($projectDir, '/\\');

        if (!is_dir($projectDir) || !is_writable($projectDir)) {
            throw new InstallerException('The project directory "{directory}" does not exist or is not writable.', 0, null, ['directory' => $projectDir]);
        }

        $report = [];
        $variables = $this->variables();

        foreach ($this->getDirectories() as $directory) {
            $path = $projectDir . '/' . $directory;

            if (!is_dir($path)) {
                $this->makeDirectory($path);
                $report[$directory . '/'] = InstallerInterface::STATUS_CREATED;
            }
        }

        foreach ($this->getFiles() as $relative => $stub) {
            $target = $projectDir . '/' . $relative;
            $exists = is_file($target);

            if ($exists && !$force) {
                $report[$relative] = in_array($relative, static::MERGEABLE, true) && $this->mergeEnv($target, strtr((string) file_get_contents($stub), $variables))
                    ? InstallerInterface::STATUS_UPDATED
                    : InstallerInterface::STATUS_SKIPPED;
                continue;
            }

            $this->makeDirectory(dirname($target));

            if (file_put_contents($target, strtr((string) file_get_contents($stub), $variables)) === false) {
                throw new InstallerException('Unable to write the file "{file}".', 0, null, ['file' => $target]);
            }

            if (in_array($relative, static::EXECUTABLES, true)) {
                @chmod($target, 0755);
            }

            $report[$relative] = $exists ? InstallerInterface::STATUS_OVERWRITTEN : InstallerInterface::STATUS_CREATED;
        }

        $report['composer.json'] = $this->configureComposer($projectDir . '/composer.json');

        return $report;
    }

    protected function variables(): array
    {
        return [
            '{{ app_secret }}' => bin2hex(random_bytes(32)),
        ];
    }

    protected function configureComposer(string $file): string
    {
        if (!is_file($file)) {
            return InstallerInterface::STATUS_SKIPPED;
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (!is_array($data)) {
            throw new InstallerException('The file "{file}" is not valid JSON.', 0, null, ['file' => $file]);
        }

        $autoload = $data['autoload']['psr-4'] ?? [];

        if (isset($autoload['App\\'])) {
            return InstallerInterface::STATUS_SKIPPED;
        }

        $data['autoload']['psr-4'] = ['App\\' => 'src/'] + (is_array($autoload) ? $autoload : []);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false || file_put_contents($file, $json . "\n") === false) {
            throw new InstallerException('Unable to update the file "{file}".', 0, null, ['file' => $file]);
        }

        return InstallerInterface::STATUS_UPDATED;
    }

    protected function mergeEnv(string $file, string $stub): bool
    {
        $content = (string) file_get_contents($file);
        $defined = [];

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (preg_match('/^\s*#?\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=/', $line, $m) === 1) {
                $defined[$m[1]] = true;
            }
        }

        $missing = [];
        $comments = [];

        foreach (preg_split('/\R/', $stub) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=/', $line, $m) === 1) {
                if (!isset($defined[$m[1]])) {
                    array_push($missing, ...$comments);
                    $missing[] = $line;
                    $defined[$m[1]] = true;
                }

                $comments = [];
            } elseif (str_starts_with(ltrim($line), '#')) {
                $comments[] = $line;
            } else {
                $comments = [];
            }
        }

        if ($missing === []) {
            return false;
        }

        $content = rtrim($content, "\r\n") . PHP_EOL . PHP_EOL . implode(PHP_EOL, $missing) . PHP_EOL;

        if (file_put_contents($file, $content) === false) {
            throw new InstallerException('Unable to update the file "{file}".', 0, null, ['file' => $file]);
        }

        return true;
    }

    protected function makeDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new InstallerException('Unable to create the directory "{directory}".', 0, null, ['directory' => $directory]);
        }
    }
}