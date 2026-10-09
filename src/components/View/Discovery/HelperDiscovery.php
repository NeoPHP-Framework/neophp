<?php

declare(strict_types=1);

namespace NeoPHP\Component\View\Discovery;

use FilesystemIterator;
use NeoPHP\Component\View\Contract\ViewHelperInterface;
use NeoPHP\Component\View\Exception\ViewException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * @internal
 */
class HelperDiscovery
{
    public const DIRECTORY = 'Helper/View';

    public const HELPER_INTERFACES = ['ViewHelperInterface', 'ViewFunctionInterface', 'ViewFilterInterface', 'ViewGlobalInterface'];

    protected array $sources = [];

    public function __construct(array $sources = [], protected bool $strict = false)
    {
        foreach ($sources as $path => $namespace) {
            $this->addSource((string) $path, (string) $namespace);
        }
    }

    public function setStrict(bool $strict): static
    {
        $this->strict = $strict;

        return $this;
    }

    public function isStrict(): bool
    {
        return $this->strict;
    }

    public function addSource(string $path, string $namespace): static
    {
        $this->sources[rtrim(str_replace('\\', '/', $path), '/')] = rtrim($namespace, '\\') . '\\';

        return $this;
    }

    public function getSources(): array
    {
        return $this->sources;
    }

    public function discover(): array
    {
        $classes = [];

        foreach ($this->sources as $path => $namespace) {
            $classes = [...$classes, ...$this->scan($path, $namespace)];
        }

        return array_values(array_unique($classes));
    }

    protected function scan(string $path, string $namespace): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $classes = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($path)), '/');

            if (!str_contains('/' . dirname($relative) . '/', '/' . static::DIRECTORY . '/')) {
                continue;
            }

            $class = $namespace . str_replace('/', '\\', substr($relative, 0, -4));

            if (!class_exists($class, !in_array($file->getRealPath(), get_included_files(), true))) {
                if ($this->strict) {
                    $this->assertDeclares($file->getPathname(), $class);
                }

                continue;
            }

            if (!is_subclass_of($class, ViewHelperInterface::class)) {
                continue;
            }

            if ((new ReflectionClass($class))->isInstantiable()) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    protected function assertDeclares(string $file, string $expected): void
    {
        $code = (string) file_get_contents($file);

        if (preg_match('/\\b(' . implode('|', self::HELPER_INTERFACES) . ')\\b/', $code) !== 1) {
            return;
        }

        $declared = self::declaredClasses($code);

        throw new ViewException(
            $declared === []
                ? 'The view helper file "{file}" does not declare the class {expected}: the file name and the namespace must match the class ({expected}).'
                : 'The view helper file "{file}" declares {found} instead of {expected}: rename the file or the class so that they match (PSR-4).',
            0,
            null,
            ['file' => $file, 'expected' => $expected, 'found' => implode(', ', $declared)],
        );
    }

    protected static function declaredClasses(string $code): array
    {
        $tokens = token_get_all($code);
        $namespace = '';
        $classes = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }

            if ($tokens[$i][0] === T_NAMESPACE) {
                $namespace = '';

                for ($j = $i + 1; $j < $count && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                    $namespace .= is_array($tokens[$j]) && $tokens[$j][0] !== T_WHITESPACE ? $tokens[$j][1] : '';
                }

                continue;
            }

            if ($tokens[$i][0] !== T_CLASS) {
                continue;
            }

            $j = $i - 1;

            while ($j >= 0 && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $j--;
            }

            if ($j >= 0 && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $classes[] = ltrim($namespace . '\\' . $tokens[$j][1], '\\');
                    break;
                }
            }
        }

        return $classes;
    }
}