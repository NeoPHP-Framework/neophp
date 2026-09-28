<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Maker;

use NeoPHP\Package\Security\Exception\SecurityException;

abstract class AbstractMaker
{
    public function __construct(protected string $path, protected string $namespace)
    {
    }

    public function resolve(string $name, string $suffix = ''): array
    {
        $name = trim(str_replace('/', '\\', $name), '\\');

        if (str_starts_with($name, trim($this->namespace, '\\') . '\\')) {
            $name = substr($name, strlen(trim($this->namespace, '\\')) + 1);
        }

        if ($suffix !== '' && str_ends_with($name, $suffix)) {
            $name = substr($name, 0, -strlen($suffix));
        }

        if (preg_match('/^([A-Z][A-Za-z0-9_]*\\\\)*[A-Z][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new SecurityException('The name "{name}" is not a valid class name: use StudlyCase (User, Post, Admin\Post).', 0, null, ['name' => $name]);
        }

        return [
            trim($this->namespace, '\\') . '\\' . $name . $suffix,
            rtrim($this->path, '/\\') . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $name . $suffix) . '.php',
            $name,
        ];
    }

    protected function write(string $file, string $code, bool $force): void
    {
        if (is_file($file) && !$force) {
            throw new SecurityException('The file "{file}" already exists: use --force to overwrite it.', 0, null, ['file' => $file]);
        }

        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new SecurityException('Unable to create the directory "{directory}".', 0, null, ['directory' => $directory]);
        }

        if (file_put_contents($file, $code) === false) {
            throw new SecurityException('Unable to write the file "{file}".', 0, null, ['file' => $file]);
        }
    }

    protected static function header(string $namespace, array $uses): string
    {
        $uses = array_values(array_unique($uses));
        sort($uses);

        return '<?php' . "\n\n"
            . 'declare(strict_types=1);' . "\n\n"
            . 'namespace ' . $namespace . ';' . "\n\n"
            . implode("\n", array_map(static fn (string $use): string => 'use ' . $use . ';', $uses)) . "\n\n";
    }

    protected static function namespaceOf(string $class): string
    {
        return substr($class, 0, (int) strrpos($class, '\\'));
    }

    protected static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}