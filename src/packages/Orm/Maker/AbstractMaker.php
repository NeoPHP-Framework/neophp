<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Maker;

use NeoPHP\Package\Orm\Exception\OrmException;

abstract class AbstractMaker
{
    public const RESERVED = [
        'abstract', 'and', 'array', 'as', 'bool', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const', 'continue', 'declare', 'default', 'do', 'echo', 'else',
        'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'false', 'final', 'finally', 'float', 'fn',
        'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include', 'instanceof', 'insteadof', 'int', 'interface', 'isset', 'iterable', 'list', 'match',
        'mixed', 'namespace', 'never', 'new', 'null', 'object', 'or', 'parent', 'print', 'private', 'protected', 'public', 'readonly', 'require', 'resource', 'return', 'self',
        'static', 'string', 'switch', 'throw', 'trait', 'true', 'try', 'unset', 'use', 'var', 'void', 'while', 'xor', 'yield',
    ];

    public function __construct(protected string $path, protected string $namespace)
    {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getNamespace(): string
    {
        return $this->namespace;
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
            throw new OrmException('The name "{name}" is not a valid class name: use StudlyCase (Post, BlogPost, Blog\Post).', 0, null, ['name' => $name]);
        }

        foreach (explode('\\', $name . $suffix) as $segment) {
            if (in_array(strtolower($segment), self::RESERVED, true)) {
                throw new OrmException('The name "{name}" is a reserved PHP word: choose another class name.', 0, null, ['name' => $segment]);
            }
        }

        $class = trim($this->namespace, '\\') . '\\' . $name . $suffix;
        $file = rtrim($this->path, '/\\') . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $name . $suffix) . '.php';

        return [$class, $file, $name];
    }

    protected function write(string $file, string $code, bool $force): void
    {
        if (is_file($file) && !$force) {
            throw new OrmException('The file "{file}" already exists: use --force to overwrite it.', 0, null, ['file' => $file]);
        }

        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new OrmException('Unable to create the directory "{directory}".', 0, null, ['directory' => $directory]);
        }

        if (file_put_contents($file, $code) === false) {
            throw new OrmException('Unable to write the file "{file}".', 0, null, ['file' => $file]);
        }
    }

    protected static function namespaceOf(string $class): string
    {
        return substr($class, 0, (int) strrpos($class, '\\'));
    }

    protected static function shortName(string $class): string
    {
        return substr($class, (int) strrpos($class, '\\') + 1);
    }
}