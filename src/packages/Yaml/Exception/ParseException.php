<?php

declare(strict_types=1);

namespace NeoPHP\Package\Yaml\Exception;

use NeoPHP\Component\Exception\Exception\FrameworkException;

class ParseException extends FrameworkException
{
    public function __construct(string $message = '', int $parsedLine = 0, string $snippet = '', ?string $parsedFile = null)
    {
        parent::__construct(
            $this->buildMessage($message, $parsedLine, $snippet, $parsedFile),
            0,
            null,
            ['line' => $parsedLine, 'snippet' => $snippet, 'file' => $parsedFile],
        );
    }

    public function getParsedLine(): int
    {
        return (int) ($this->context['line'] ?? 0);
    }

    public function getSnippet(): string
    {
        return (string) ($this->context['snippet'] ?? '');
    }

    public function getParsedFile(): ?string
    {
        return $this->context['file'] ?? null;
    }

    public function withFile(string $file): static
    {
        $message = preg_replace('/ at line \d+.*$/s', '', $this->getMessage()) ?? $this->getMessage();

        return new static(rtrim($message, '.'), $this->getParsedLine(), $this->getSnippet(), $file);
    }

    private function buildMessage(string $message, int $line, string $snippet, ?string $file): string
    {
        if ($line > 0) {
            $message .= sprintf(' at line %d', $line);
        }

        if ($file !== null) {
            $message .= sprintf(' in "%s"', $file);
        }

        if ($snippet !== '') {
            $message .= sprintf(' (near "%s")', trim($snippet));
        }

        return $message . '.';
    }
}