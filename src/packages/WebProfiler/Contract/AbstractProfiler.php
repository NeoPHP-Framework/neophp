<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Contract;

use NeoPHP\Package\WebProfiler\Util\ValueExporter;
use ReflectionClass;

abstract class AbstractProfiler implements ProfilerElementInterface
{
    public const PRIORITY = 0;

    public function getName(): string
    {
        $name = (new ReflectionClass($this))->getShortName();

        if (str_ends_with($name, 'Profiler') && $name !== 'Profiler') {
            $name = substr($name, 0, -8);
        }

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    public function getPriority(): int
    {
        return static::PRIORITY;
    }

    protected function export(mixed $value, int $depth = ValueExporter::DEFAULT_DEPTH): mixed
    {
        return ValueExporter::export($value, $depth);
    }
}