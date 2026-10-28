<?php

declare(strict_types=1);

namespace NeoPHP\Process\Doctor\Model;

use NeoPHP\Process\Doctor\Exception\DoctorException;

class CheckResult
{
    public const OK = 'ok';

    public const INFO = 'info';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    public const STATUSES = [
        self::OK,
        self::INFO,
        self::WARNING,
        self::ERROR
    ];

    /**
     * @param string $status ok, info, warning (should be fixed) or error (something will not work)
     * @param string $label What was checked (e.g. Extension gd)
     * @param string $message What was found (e.g. 8.3.12, missing)
     * @param string|null $fix How to fix it
     * @throws DoctorException
     */
    public function __construct(
        protected string $status,
        protected string $label,
        protected string $message = '',
        protected ?string $fix = null,
    ) {
        if (!in_array($status, self::STATUSES, true)) {
            throw new DoctorException('The status "{status}" is unknown: use {statuses}.', 0, null, ['status' => $status, 'statuses' => implode(', ', self::STATUSES)]);
        }
    }

    public static function ok(string $label, string $message = ''): static
    {
        return new static(self::OK, $label, $message);
    }

    public static function info(string $label, string $message = '', ?string $fix = null): static
    {
        return new static(self::INFO, $label, $message, $fix);
    }

    public static function warning(string $label, string $message = '', ?string $fix = null): static
    {
        return new static(self::WARNING, $label, $message, $fix);
    }

    public static function error(string $label, string $message = '', ?string $fix = null): static
    {
        return new static(self::ERROR, $label, $message, $fix);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getFix(): ?string
    {
        return $this->fix;
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }

    public function isWarning(): bool
    {
        return $this->status === self::WARNING;
    }

    public function isError(): bool
    {
        return $this->status === self::ERROR;
    }

    /**
     * @return array{status: string, label: string, message: string, fix: string|null}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'label' => $this->label,
            'message' => $this->message,
            'fix' => $this->fix
        ];
    }
}