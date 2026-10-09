<?php

declare(strict_types=1);

use NeoPHP\Package\Debug\DebugManager;

if (!function_exists('dump')) {
    function dump(mixed ...$values): mixed
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        DebugManager::getInstance()->dumpFrom(isset($caller['file']) ? $caller['file'] . ':' . ($caller['line'] ?? 0) : null, $values);

        return count($values) === 1 ? $values[array_key_first($values)] : $values;
    }
}

if (!function_exists('dd')) {
    function dd(mixed ...$values): never
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        DebugManager::getInstance()->ddFrom(isset($caller['file']) ? $caller['file'] . ':' . ($caller['line'] ?? 0) : null, $values);
    }
}