<?php

declare(strict_types=1);

namespace NeoPHP\Component\Logger\Contract;

use NeoPHP\Component\Logger\Exception\LoggerException;

class LogLevel
{
    public const EMERGENCY = 'emergency';
    public const ALERT = 'alert';
    public const CRITICAL = 'critical';
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const NOTICE = 'notice';
    public const INFO = 'info';
    public const DEBUG = 'debug';

    public const SEVERITIES = [
        self::DEBUG => 100,
        self::INFO => 200,
        self::NOTICE => 250,
        self::WARNING => 300,
        self::ERROR => 400,
        self::CRITICAL => 500,
        self::ALERT => 550,
        self::EMERGENCY => 600,
    ];

    public static function normalize(mixed $level): string
    {
        $normalized = is_string($level) ? strtolower(trim($level)) : '';

        if (!isset(self::SEVERITIES[$normalized])) {
            throw new LoggerException('Invalid log level "{level}". Expected one of: {levels}.', 0, null, [
                'level' => is_scalar($level) ? (string) $level : get_debug_type($level),
                'levels' => implode(', ', array_keys(self::SEVERITIES)),
            ]);
        }

        return $normalized;
    }

    public static function severity(string $level): int
    {
        return self::SEVERITIES[self::normalize($level)];
    }
}