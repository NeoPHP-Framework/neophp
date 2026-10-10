<?php

declare(strict_types=1);

namespace NeoPHP\Process\Package;

use FilesystemIterator;
use NeoPHP\Component\Kernel\Attribute\Process;
use NeoPHP\Component\Kernel\Module\InstalledPackages;
use NeoPHP\Process\Package\Composer\ComposerRunner;
use NeoPHP\Process\Package\Exception\PackageException;
use NeoPHP\Process\Package\Provider\PackageProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use stdClass;

#[Process(provider: PackageProvider::class)]
final class PackageManager implements PackageManagerInterface
{
    public const CONFIG_DIRECTORY = 'config/packages';

    public const MODULES_FILE = 'config/config.php';

    public const ROUTES_FILE = 'config/routes.yaml';

    public const CACHE_DIRECTORY = 'var/cache';

    public const MANIFEST = '.package.json';

    public const DIST_EXTENSION = '.dist';

    public const NAME_PATTERN = '#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#';

    protected string $rootPath;

    protected ComposerRunner $composer;

    public function __construct(string $rootPath, ?ComposerRunner $composer = null)
    {
        $this->rootPath = rtrim($rootPath, '/\\');
        $this->composer = $composer ?? new ComposerRunner($this->rootPath);
    }

    public function all(): array
    {
        return InstalledPackages::all($this->rootPath);
    }

    public function find(string $package): ?array
    {
        return InstalledPackages::find($this->rootPath, strtolower(trim($package)));
    }

    public function install(string $package, bool $dev = false, bool $force = false): array
    {
        [$name, $constraint] = $this->parse($package);
        $this->assertInstallable($name);

        $this->composer(['require', $constraint === null ? $name : $name . ':' . $constraint, ...($dev ? ['--dev'] : [])]);
        $installed = $this->find($name);

        if ($installed === null) {
            $this->composer->run(['remove', $name, ...($dev ? ['--dev'] : [])]);

            throw new PackageException('"{package}" is not a NeoPHP package (Composer type "{type}"): it has been removed.', 0, null, ['package' => $name, 'type' => InstalledPackages::TYPE]);
        }

        $files = $this->publish($installed, $force);

        if ($this->addRoutesImport($installed)) {
            $files[self::ROUTES_FILE] = self::STATUS_UPDATED;
        }

        $this->clearCache();

        return ['package' => $installed, 'files' => $files];
    }

    public function update(?string $package = null, bool $force = false): array
    {
        $packages = $package === null || trim($package) === '' ? $this->all() : [$this->installed($package)];

        if ($packages === []) {
            return [];
        }

        $names = array_values(array_map(static fn (array $installed): string => $installed['name'], $packages));
        $this->composer(['update', ...$names, '--with-dependencies']);
        $report = [];

        foreach ($names as $name) {
            $updated = $this->find($name);

            if ($updated !== null) {
                $report[$name] = ['package' => $updated, 'files' => $this->publish($updated, $force)];
            }
        }

        $this->clearCache();

        return $report;
    }

    public function remove(string $package, bool $purge = false): array
    {
        $installed = $this->installed($package);
        $modulesFile = $this->rootPath . DIRECTORY_SEPARATOR . self::MODULES_FILE;
        $routesFile = $this->rootPath . DIRECTORY_SEPARATOR . self::ROUTES_FILE;
        $previous = [];
        $files = [];

        foreach ([$modulesFile, $routesFile] as $file) {
            if (is_file($file)) {
                $previous[$file] = (string) file_get_contents($file);
            }
        }

        if ($this->removeModuleEntries($modulesFile, $installed['modules'])) {
            $files[self::MODULES_FILE] = self::STATUS_UPDATED;
        }

        if ($this->removeRoutesImport($installed)) {
            $files[self::ROUTES_FILE] = self::STATUS_UPDATED;
        }

        try {
            $this->composer(['remove', $installed['name']]);
        } catch (PackageException $exception) {
            foreach ($previous as $file => $content) {
                file_put_contents($file, $content);
            }

            throw $exception;
        }

        $directory = $this->getConfigDirectory($installed['alias']);

        if ($purge && is_dir($directory)) {
            $this->deleteDirectory($directory, true);
            $files[$this->relative($directory)] = self::STATUS_REMOVED;
        }

        $this->clearCache();

        return ['package' => $installed, 'files' => $files];
    }

    public function publishConfig(string $package, bool $force = false): array
    {
        return $this->publish($this->installed($package), $force);
    }

    public function addPathRepository(string $path): bool
    {
        $file = $this->rootPath . DIRECTORY_SEPARATOR . 'composer.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file)) : null;

        if (!$data instanceof stdClass) {
            throw new PackageException('The file "{file}" is missing or is not valid JSON.', 0, null, ['file' => $file]);
        }

        $url = rtrim($this->relative($path), '/');
        $repositories = $data->repositories ?? [];

        foreach ((array) $repositories as $repository) {
            if ($repository instanceof stdClass && ($repository->type ?? null) === 'path' && rtrim(str_replace('\\', '/', (string) ($repository->url ?? '')), '/') === $url) {
                return false;
            }
        }

        $repository = (object) ['type' => 'path', 'url' => $url, 'options' => (object) ['symlink' => true]];

        if ($repositories instanceof stdClass) {
            $repositories->{basename($url)} = $repository;
        } else {
            $repositories = [...(array) $repositories, $repository];
        }

        $data->repositories = $repositories;
        $this->write($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        return true;
    }

    public function getConfigDirectory(string $package): string
    {
        $alias = $this->find($package)['alias'] ?? $package;

        return $this->rootPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::CONFIG_DIRECTORY) . DIRECTORY_SEPARATOR . $alias;
    }

    public function clearCache(): int
    {
        $removed = $this->deleteDirectory($this->rootPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::CACHE_DIRECTORY), false);

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return $removed;
    }

    protected function publish(array $package, bool $force): array
    {
        if ($package['config'] === null) {
            return [];
        }

        $target = $this->getConfigDirectory($package['alias']);
        $manifestFile = $target . DIRECTORY_SEPARATOR . self::MANIFEST;
        $manifest = is_file($manifestFile) ? (array) json_decode((string) file_get_contents($manifestFile), true) : [];
        $published = (array) ($manifest['files'] ?? []);
        $report = [];

        foreach ($this->files($package['config']) as $relative => $source) {
            $file = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $hash = (string) sha1_file($source);
            $current = is_file($file) ? (string) sha1_file($file) : null;
            $previous = isset($published[$relative]) ? (string) $published[$relative] : null;

            $status = match (true) {
                $current === null => self::STATUS_CREATED,
                $current === $hash => self::STATUS_SKIPPED,
                $force => self::STATUS_OVERWRITTEN,
                $previous !== null && $current === $previous => self::STATUS_UPDATED,
                $previous === $hash => self::STATUS_SKIPPED,
                default => self::STATUS_CHANGED,
            };

            if (in_array($status, [self::STATUS_CREATED, self::STATUS_OVERWRITTEN, self::STATUS_UPDATED], true)) {
                $this->copy($source, $file);
                $this->delete($file . self::DIST_EXTENSION);
            } elseif ($status === self::STATUS_CHANGED) {
                $this->copy($source, $file . self::DIST_EXTENSION);
            } elseif ($current === $hash) {
                $this->delete($file . self::DIST_EXTENSION);
            }

            $published[$relative] = $hash;
            $report[$this->relative($status === self::STATUS_CHANGED ? $file . self::DIST_EXTENSION : $file)] = $status;
        }

        if ($report !== []) {
            ksort($published);
            $this->write($manifestFile, json_encode(['package' => $package['name'], 'version' => $package['version'], 'files' => $published], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }

        return $report;
    }

    protected function installed(string $package): array
    {
        $installed = $this->find($package);

        if ($installed === null) {
            throw new PackageException('The NeoPHP package "{package}" is not installed. Installed packages: {installed}.', 0, null, [
                'package' => $package,
                'installed' => $this->all() === [] ? 'none' : implode(', ', array_keys($this->all())),
            ]);
        }

        return $installed;
    }

    protected function assertInstallable(string $name): void
    {
        $output = $this->composer->capture(['show', '--all', '--format=json', '--no-interaction', $name]);
        $data = $output !== null ? json_decode($output, true) : null;

        if (is_array($data) && isset($data['type']) && $data['type'] !== InstalledPackages::TYPE) {
            throw new PackageException('"{package}" is not a NeoPHP package: its Composer type is "{type}", "{expected}" is expected.', 0, null, [
                'package' => $name,
                'type' => (string) $data['type'],
                'expected' => InstalledPackages::TYPE,
            ]);
        }
    }

    protected function parse(string $package): array
    {
        $package = trim($package);
        [$name, $constraint] = str_contains($package, ':') ? explode(':', $package, 2) : [$package, null];
        $name = strtolower(trim($name));
        $constraint = $constraint !== null && trim($constraint) !== '' ? trim($constraint) : null;

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new PackageException('"{package}" is not a valid Composer package name: vendor/name is expected (e.g. acme/neo-billing).', 0, null, ['package' => $package]);
        }

        return [$name, $constraint];
    }

    protected function composer(array $arguments): void
    {
        $code = $this->composer->run([...$arguments, '--working-dir=' . $this->rootPath]);

        if ($code !== 0) {
            throw new PackageException('Composer failed (exit code {code}): {command}.', 0, null, ['code' => $code, 'command' => $this->composer->display($arguments)]);
        }
    }

    protected function addRoutesImport(array $package): bool
    {
        if ($package['routes'] === null) {
            return false;
        }

        $file = $this->rootPath . DIRECTORY_SEPARATOR . self::ROUTES_FILE;
        $content = is_file($file) ? (string) file_get_contents($file) : '';

        if (preg_match($this->routesImportPattern($package['alias']), $content) === 1) {
            return false;
        }

        $block = sprintf("package_%s:\n    resource: '@%s'\n", $package['alias'], $package['alias']);
        $this->write($file, trim($content) === '' ? $block : rtrim($content) . "\n\n" . $block);

        return true;
    }

    protected function removeRoutesImport(array $package): bool
    {
        $file = $this->rootPath . DIRECTORY_SEPARATOR . self::ROUTES_FILE;

        if (!is_file($file)) {
            return false;
        }

        $pattern = $this->routesImportPattern($package['alias']);
        $kept = [];
        $block = [];
        $removed = false;

        foreach ([...explode("\n", (string) file_get_contents($file)), null] as $line) {
            $boundary = $line === null || ($line !== '' && !ctype_space($line[0]));

            if ($boundary && $block !== []) {
                if (preg_match($pattern, implode("\n", $block)) === 1) {
                    $removed = true;

                    while ($kept !== [] && trim((string) end($kept)) === '') {
                        array_pop($kept);
                    }

                    if ($line !== null && $kept !== []) {
                        $kept[] = '';
                    }
                } else {
                    array_push($kept, ...$block);
                }

                $block = [];
            }

            if ($line === null) {
                break;
            }

            if ($boundary && !str_starts_with($line, '#')) {
                $block[] = $line;
            } elseif ($block !== [] && !$boundary) {
                $block[] = $line;
            } else {
                $kept[] = $line;
            }
        }

        if (!$removed) {
            return false;
        }

        $this->write($file, rtrim(implode("\n", $kept)) . "\n");

        return true;
    }

    protected function routesImportPattern(string $alias): string
    {
        return '/^[ \t]+resource:[ \t]*[\'"]?@' . preg_quote($alias, '/') . '[\'"]?[ \t]*$/m';
    }

    protected function removeModuleEntries(string $file, array $modules): bool
    {
        if ($modules === [] || !is_file($file)) {
            return false;
        }

        $content = (string) file_get_contents($file);
        $updated = $content;

        foreach ($modules as $module) {
            $updated = (string) preg_replace('/^[ \t]*\\\\?' . preg_quote(ltrim((string) $module, '\\'), '/') . '::class[ \t]*=>[^\n]*\n?/m', '', $updated);
        }

        if ($updated === $content) {
            return false;
        }

        $this->write($file, $updated);

        return true;
    }

    protected function files(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($directory))), '/')] = $file->getPathname();
            }
        }

        ksort($files);

        return $files;
    }

    protected function copy(string $source, string $target): void
    {
        $this->write($target, (string) file_get_contents($source));
    }

    protected function write(string $file, string $content): void
    {
        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new PackageException('Unable to create the directory "{directory}".', 0, null, ['directory' => $directory]);
        }

        if (file_put_contents($file, $content) === false) {
            throw new PackageException('Unable to write the file "{file}".', 0, null, ['file' => $file]);
        }
    }

    protected function delete(string $file): void
    {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    protected function deleteDirectory(string $directory, bool $removeRoot): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $removed = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || (!$removeRoot && $file->getFilename() === '.gitkeep')) {
                continue;
            }

            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } elseif (@unlink($file->getPathname())) {
                $removed++;
            }
        }

        if ($removeRoot) {
            @rmdir($directory);
        }

        return $removed;
    }

    protected function relative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', $this->rootPath) . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}