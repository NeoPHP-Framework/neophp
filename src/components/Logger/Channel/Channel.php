<?php

declare(strict_types=1);

namespace NeoPHP\Component\Logger\Channel;

use DateTimeImmutable;
use DateTimeZone;
use NeoPHP\Component\Logger\Contract\AbstractLogger;
use NeoPHP\Component\Logger\Contract\LogLevel;
use NeoPHP\Component\Logger\Formatter\LineFormatter;
use NeoPHP\Component\Logger\Writer\FileWriter;
use Stringable;
use Throwable;

class Channel extends AbstractLogger
{
    protected int $minimumSeverity;

    public function __construct(
        protected string $name,
        protected ?FileWriter $writer,
        protected LineFormatter $formatter,
        string $minimumLevel = LogLevel::DEBUG,
        protected bool $enabled = true,
        protected ?DateTimeZone $timezone = null,
    ) {
        $this->minimumSeverity = LogLevel::severity($minimumLevel);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->writer !== null;
    }

    public function getFile(): ?string
    {
        return $this->writer?->getFile();
    }

    public function isHandling(string $level): bool
    {
        return $this->isEnabled() && LogLevel::severity($level) >= $this->minimumSeverity;
    }

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $level = LogLevel::normalize($level);

        if (!$this->isHandling($level)) {
            return;
        }

        $now = new DateTimeImmutable('now', $this->timezone);
        $line = $this->formatter->format($this->name, $level, $message, $context, $now);

        try {
            $this->writer->write($line, $now);
        } catch (Throwable $exception) {
            error_log(sprintf('[NeoPHP] Logger channel "%s" failed: %s. Message: %s', $this->name, $exception->getMessage(), trim($line)));
        }
    }
}