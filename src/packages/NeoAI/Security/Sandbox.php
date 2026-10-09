<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Security;

use FilesystemIterator;
use NeoPHP\Package\NeoAI\Exception\SandboxException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class Sandbox
{
    public const EXCLUDED = ['vendor', 'var', 'node_modules', '.git', '.idea', '.env', '.env.*', '*.key', '*.pem', '*.p12', '*.pfx', '*.crt', 'id_rsa*', '*.sqlite', '*.db', '.htpasswd', 'auth.json'];

    protected string $root;

    protected array $excluded;

    public function __construct(string $root, array $excluded = self::EXCLUDED, protected int $maxFileBytes = 60000)
    {
        $real = realpath($root);

        if ($real === false || !is_dir($real)) {
            throw new SandboxException('The project root "{root}" does not exist.', 0, null, ['root' => $root]);
        }

        $this->root = rtrim(str_replace('\\', '/', $real), '/');
        $this->excluded = array_values(array_unique(array_filter(array_map(static fn (mixed $pattern): string => trim(str_replace('\\', '/', (string) $pattern), '/'), $excluded), static fn (string $pattern): bool => $pattern !== '')));
    }

    public function getRoot(): string
    {
        return $this->root;
    }

    public function getExcluded(): array
    {
        return $this->excluded;
    }

    public function getMaxFileBytes(): int
    {
        return $this->maxFileBytes;
    }

    public function normalize(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new SandboxException('Invalid path "{path}".', 0, null, ['path' => str_replace("\0", '\0', $path)]);
        }

        $path = str_replace('\\', '/', trim($path));

        if (str_starts_with($path, $this->root . '/') || $path === $this->root) {
            $path = substr($path, strlen($this->root));
        } elseif (preg_match('#^([a-zA-Z]:)?/#', $path) === 1 || str_starts_with($path, '~')) {
            throw new SandboxException('Access denied: "{path}" is outside the project root.', 0, null, ['path' => $path]);
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    throw new SandboxException('Access denied: "{path}" is outside the project root.', 0, null, ['path' => $path]);
                }

                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    public function resolve(string $path, bool $mustExist = true): string
    {
        $relative = $this->normalize($path);

        if ($this->isExcluded($relative)) {
            throw new SandboxException('Access denied: "{path}" is excluded (excluded_paths).', 0, null, ['path' => $relative]);
        }

        $absolute = $relative === '' ? $this->root : $this->root . '/' . $relative;
        $real = realpath($absolute);

        if ($real === false) {
            if ($mustExist) {
                throw new SandboxException('The path "{path}" does not exist.', 0, null, ['path' => $relative]);
            }

            $parent = dirname($absolute);

            while (!is_dir($parent) && $parent !== dirname($parent)) {
                $parent = dirname($parent);
            }

            $real = realpath($parent);

            if ($real === false || !$this->contains($real)) {
                throw new SandboxException('Access denied: "{path}" is outside the project root.', 0, null, ['path' => $relative]);
            }

            return $absolute;
        }

        if (!$this->contains($real)) {
            throw new SandboxException('Access denied: "{path}" resolves outside the project root.', 0, null, ['path' => $relative]);
        }

        if ($this->isExcluded($this->relative($real))) {
            throw new SandboxException('Access denied: "{path}" is excluded (excluded_paths).', 0, null, ['path' => $relative]);
        }

        return str_replace('\\', '/', $real);
    }

    public function relative(string $absolute): string
    {
        $absolute = str_replace('\\', '/', $absolute);

        return $absolute === $this->root ? '' : ltrim(str_starts_with($absolute, $this->root . '/') ? substr($absolute, strlen($this->root)) : $absolute, '/');
    }

    public function isExcluded(string $relative): bool
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');

        if ($relative === '') {
            return false;
        }

        $segments = explode('/', $relative);

        foreach ($this->excluded as $pattern) {
            if (fnmatch($pattern, $relative) || str_starts_with($relative, $pattern . '/')) {
                return true;
            }

            if (str_contains($pattern, '/')) {
                continue;
            }

            foreach ($segments as $segment) {
                if (fnmatch($pattern, $segment)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function read(string $path, int $from = 1, ?int $to = null): array
    {
        $file = $this->resolve($path);

        if (!is_file($file) || !is_readable($file)) {
            throw new SandboxException('"{path}" is not a readable file.', 0, null, ['path' => $this->relative($file)]);
        }

        $content = (string) file_get_contents($file);

        if (str_contains(substr($content, 0, 8000), "\0")) {
            throw new SandboxException('"{path}" is a binary file.', 0, null, ['path' => $this->relative($file)]);
        }

        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $total = count($lines);
        $from = max(1, $from);
        $to = $to === null ? $total : max($from, min($total, $to));
        $output = '';
        $truncated = false;

        for ($number = $from; $number <= $to; $number++) {
            $line = sprintf("%5d| %s\n", $number, $lines[$number - 1] ?? '');

            if (strlen($output) + strlen($line) > $this->maxFileBytes) {
                $truncated = true;
                $to = $number - 1;
                break;
            }

            $output .= $line;
        }

        return [
            'path' => $this->relative($file),
            'content' => $output,
            'raw' => $content,
            'hash' => sha1($content),
            'from' => $from,
            'to' => $to,
            'total' => $total,
            'truncated' => $truncated,
        ];
    }

    public function listFiles(string $directory = '', string $pattern = '*', int $limit = 200, bool $recursive = true): array
    {
        $base = $this->resolve($directory === '' ? '.' : $directory);

        if (!is_dir($base)) {
            throw new SandboxException('"{path}" is not a directory.', 0, null, ['path' => $this->relative($base)]);
        }

        $files = [];
        $this->walk($base, $recursive, function (SplFileInfo $file, string $relative) use (&$files, $pattern, $limit): bool {
            if ($pattern === '' || $pattern === '*' || fnmatch($pattern, $file->getFilename()) || fnmatch($pattern, $relative)) {
                $files[] = $relative . ($file->isDir() ? '/' : '');
            }

            return count($files) < $limit;
        });

        sort($files);

        return $files;
    }

    public function search(string $regex, string $glob = '*', int $limit = 100, string $directory = ''): array
    {
        $pattern = '~' . str_replace('~', '\~', $regex) . '~u';

        if (@preg_match($pattern, '') === false) {
            $pattern = '~' . preg_quote($regex, '~') . '~iu';
        }

        $base = $this->resolve($directory === '' ? '.' : $directory);
        $matches = [];
        $this->walk($base, true, function (SplFileInfo $file, string $relative) use (&$matches, $pattern, $glob, $limit): bool {
            if ($file->isDir() || ($glob !== '' && $glob !== '*' && !fnmatch($glob, $file->getFilename()) && !fnmatch($glob, $relative)) || $file->getSize() > 1048576) {
                return true;
            }

            $handle = @fopen($file->getPathname(), 'rb');

            if ($handle === false) {
                return true;
            }

            $number = 0;

            while (($line = fgets($handle)) !== false) {
                $number++;

                if (str_contains($line, "\0")) {
                    break;
                }

                if (@preg_match($pattern, $line) === 1) {
                    $matches[] = ['file' => $relative, 'line' => $number, 'text' => mb_substr(trim($line), 0, 240)];

                    if (count($matches) >= $limit) {
                        fclose($handle);

                        return false;
                    }
                }
            }

            fclose($handle);

            return true;
        });

        return $matches;
    }

    protected function walk(string $base, bool $recursive, callable $visitor): void
    {
        $iterator = new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS);
        $items = $recursive ? new RecursiveIteratorIterator(new SandboxFilterIterator($iterator, $this), RecursiveIteratorIterator::SELF_FIRST) : $iterator;

        foreach ($items as $file) {
            if (!$file instanceof SplFileInfo || $file->isLink()) {
                continue;
            }

            $relative = $this->relative($file->getPathname());

            if ($this->isExcluded($relative)) {
                continue;
            }

            if (!$recursive || $file->isFile()) {
                if ($visitor($file, $relative) === false) {
                    return;
                }
            }
        }
    }

    protected function contains(string $real): bool
    {
        $real = str_replace('\\', '/', $real);

        return $real === $this->root || str_starts_with($real, $this->root . '/');
    }
}