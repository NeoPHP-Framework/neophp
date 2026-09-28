<?php

declare(strict_types=1);

namespace NeoPHP\Component\Asset\Compiler;

class CssCompiler implements CompilerInterface
{
    public const URL_PATTERN = '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i';

    public const IMPORT_PATTERN = '/@import\s+([\'"])([^\'"]+)\1/i';

    public function supports(string $extension): bool
    {
        return $extension === 'css';
    }

    public function compile(string $content, string $path, callable $resolve, bool $minify = false): string
    {
        $content = (string) preg_replace_callback(static::URL_PATTERN, function (array $match) use ($path, $resolve): string {
            $url = $this->rewrite($match[2], $path, $resolve);

            return $url === null ? $match[0] : 'url(' . $match[1] . $url . $match[1] . ')';
        }, $content);

        $content = (string) preg_replace_callback(static::IMPORT_PATTERN, function (array $match) use ($path, $resolve): string {
            $url = $this->rewrite($match[2], $path, $resolve);

            return $url === null ? $match[0] : '@import ' . $match[1] . $url . $match[1];
        }, $content);

        return $minify ? $this->minify($content) : $content;
    }

    protected function rewrite(string $reference, string $path, callable $resolve): ?string
    {
        $reference = trim($reference);

        if ($reference === '' || str_starts_with($reference, '#') || str_starts_with($reference, '/') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $reference) === 1) {
            return null;
        }

        $position = strcspn($reference, '?#');
        $suffix = substr($reference, $position);
        $target = $this->normalize(dirname($path) . '/' . substr($reference, 0, $position));

        if ($target === null) {
            return null;
        }

        $url = $resolve($target);

        return is_string($url) ? $this->relative(dirname($path), dirname($target), basename((string) parse_url($url, PHP_URL_PATH))) . $suffix : null;
    }

    protected function relative(string $from, string $to, string $file): string
    {
        $from = $from === '.' ? [] : explode('/', trim($from, '/'));
        $to = $to === '.' ? [] : explode('/', trim($to, '/'));

        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return implode('/', [...array_fill(0, count($from), '..'), ...$to, $file]);
    }

    protected function normalize(string $path): ?string
    {
        $parts = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($parts === []) {
                    return null;
                }

                array_pop($parts);
                continue;
            }

            $parts[] = $segment;
        }

        return $parts === [] ? null : implode('/', $parts);
    }

    protected function minify(string $content): string
    {
        $output = '';
        $pending = false;
        $length = strlen($content);

        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];

            if ($char === '/' && ($content[$i + 1] ?? '') === '*') {
                $end = strpos($content, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                $pending = true;
                continue;
            }

            if (ctype_space($char)) {
                $pending = true;
                continue;
            }

            if ($pending && $output !== '' && !str_contains('{};,:', substr($output, -1)) && !str_contains('{};,', $char)) {
                $output .= ' ';
            }

            $pending = false;

            if ($char === '"' || $char === "'") {
                $end = $i + 1;

                while ($end < $length && $content[$end] !== $char) {
                    $end += $content[$end] === '\\' ? 2 : 1;
                }

                $output .= substr($content, $i, $end - $i + 1);
                $i = $end;
                continue;
            }

            if ($char === '}' && str_ends_with($output, ';')) {
                $output = substr($output, 0, -1);
            }

            $output .= $char;
        }

        return $output;
    }
}