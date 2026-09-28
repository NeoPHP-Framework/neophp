<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Dumper;

use NeoPHP\Package\Translation\Contract\DumperInterface;

class YamlDumper implements DumperInterface
{
    public function dump(array $messages, string $locale, string $domain, string $sourceLocale): string
    {
        $tree = [];

        foreach ($messages as $key => $message) {
            $key = (string) $key;
            $segments = self::segments($key);

            if ($segments === null || !self::insert($tree, $segments, (string) $message)) {
                $tree[$key] = (string) $message;
            }
        }

        return $tree === [] ? "{}\n" : self::render($tree, 0);
    }

    public function getExtension(): string
    {
        return 'yaml';
    }

    protected static function segments(string $key): ?array
    {
        if (!str_contains($key, '.')) {
            return null;
        }

        $segments = explode('.', $key);

        foreach ($segments as $segment) {
            if (preg_match('/^[A-Za-z0-9_\-]+$/', $segment) !== 1) {
                return null;
            }
        }

        return $segments;
    }

    protected static function insert(array &$tree, array $segments, string $message): bool
    {
        $node = &$tree;
        $last = array_pop($segments);

        foreach ($segments as $segment) {
            if (!isset($node[$segment])) {
                $node[$segment] = [];
            } elseif (!is_array($node[$segment])) {
                return false;
            }

            $node = &$node[$segment];
        }

        if (isset($node[$last]) && is_array($node[$last])) {
            return false;
        }

        $node[$last] = $message;

        return true;
    }

    protected static function render(array $tree, int $depth): string
    {
        $output = '';
        $indent = str_repeat('    ', $depth);

        foreach ($tree as $key => $value) {
            if (is_array($value)) {
                $output .= $indent . self::key((string) $key) . ":\n" . self::render($value, $depth + 1);
                continue;
            }

            $output .= $indent . self::key((string) $key) . ': ' . self::quote($value) . "\n";
        }

        return $output;
    }

    protected static function key(string $key): string
    {
        return preg_match('/^[A-Za-z0-9_\-]+$/', $key) === 1 && !in_array(strtolower($key), ['true', 'false', 'null', 'yes', 'no', 'on', 'off', '~'], true) && !is_numeric($key)
            ? $key
            : self::quote($key);
    }

    public static function quote(string $value): string
    {
        return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']) . '"';
    }
}