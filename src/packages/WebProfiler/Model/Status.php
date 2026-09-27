<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Model;

class Status
{
    public const DEFAULT = 'default';

    public const SUCCESS = 'success';

    public const INFO = 'info';

    public const WARNING = 'warning';

    public const DANGER = 'danger';

    public const ALL = [self::DEFAULT, self::SUCCESS, self::INFO, self::WARNING, self::DANGER];

    public static function normalize(?string $status): string
    {
        return in_array($status, self::ALL, true) ? (string) $status : self::DEFAULT;
    }

    public static function fromHttpCode(int $code): string
    {
        return match (true) {
            $code >= 500 => self::DANGER,
            $code >= 400 => self::WARNING,
            $code >= 300 => self::INFO,
            default => self::SUCCESS,
        };
    }
}