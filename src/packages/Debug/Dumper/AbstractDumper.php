<?php

declare(strict_types=1);

namespace NeoPHP\Package\Debug\Dumper;

use NeoPHP\Package\Debug\Contract\DumperInterface;

abstract class AbstractDumper implements DumperInterface
{
    public const VISIBILITY_PREFIXES = [
        'public' => '+',
        'protected' => '#',
        'private' => '-',
        'virtual' => '~',
    ];

    protected static function formatFloat(float $value): string
    {
        if (is_nan($value)) {
            return 'NAN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? 'INF' : '-INF';
        }

        $string = var_export($value, true);

        return str_contains($string, '.') || str_contains($string, 'E') ? $string : $string . '.0';
    }

    protected static function escapeString(string $value, bool $binary): string
    {
        $value = addcslashes($value, "\\\"\0..\x1F\x7F");

        return $binary ? (string) preg_replace_callback('/[\x80-\xFF]/', static fn (array $m): string => '\\x' . strtoupper(bin2hex($m[0])), $value) : $value;
    }

    protected static function shortClass(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}