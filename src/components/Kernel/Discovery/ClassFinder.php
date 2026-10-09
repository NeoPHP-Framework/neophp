<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Discovery;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class ClassFinder
{
    protected array $resources = [];

    public function find(string $path, string|array|null $contains = null): array
    {
        return array_keys($this->map($path, $contains));
    }

    public function map(string $path, string|array|null $contains = null): array
    {
        $classes = [];

        foreach ($this->files($path) as $file) {
            $content = (string) file_get_contents($file);

            if ($contains !== null && !$this->contains($content, (array) $contains)) {
                continue;
            }

            foreach ($this->classesIn($content) as $class) {
                $classes[$class] ??= $file;
            }
        }

        return $classes;
    }

    public function getResources(): array
    {
        return $this->resources;
    }

    protected function contains(string $content, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($content, (string) $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function files(string $path): array
    {
        if (is_file($path)) {
            $this->resources[$path] = (int) filemtime($path);

            return [$path];
        }

        if (!is_dir($path)) {
            return [];
        }

        $this->resources[$path] = (int) filemtime($path);
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            if ($file->isDir()) {
                $this->resources[$file->getPathname()] = (int) $file->getMTime();
            } elseif ($file->getExtension() === 'php') {
                $this->resources[$file->getPathname()] = (int) $file->getMTime();
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    protected function classesIn(string $content): array
    {
        $tokens = token_get_all($content);
        $count = count($tokens);
        $namespace = '';
        $classes = [];

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }

            if ($tokens[$i][0] === T_NAMESPACE) {
                $namespace = '';

                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j] === ';' || $tokens[$j] === '{') {
                        break;
                    }

                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                        $namespace .= $tokens[$j][1];
                    }
                }

                continue;
            }

            if ($tokens[$i][0] !== T_CLASS || in_array($this->previousToken($tokens, $i), [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $classes[] = ltrim($namespace . '\\' . $tokens[$j][1], '\\');
                    break;
                }

                if ($tokens[$j] === '{' || $tokens[$j] === '(') {
                    break;
                }
            }
        }

        return $classes;
    }

    protected function previousToken(array $tokens, int $index): int|string|null
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($tokens[$i]) ? $tokens[$i][0] : $tokens[$i];
        }

        return null;
    }
}