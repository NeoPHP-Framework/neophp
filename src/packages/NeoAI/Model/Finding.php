<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Model;

class Finding
{
    public const SEVERITIES = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1, 'info' => 0];

    protected string $severity;

    public function __construct(
        string $severity,
        protected string $file,
        protected ?int $line,
        protected string $title,
        protected string $explanation = '',
        protected string $fix = '',
        protected string $category = 'general',
        protected string $diff = '',
    ) {
        $severity = strtolower(trim($severity));
        $this->severity = isset(self::SEVERITIES[$severity]) ? $severity : 'info';
    }

    public static function fromArray(array $data): ?static
    {
        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '') {
            return null;
        }

        $line = $data['line'] ?? null;

        return new static(
            (string) ($data['severity'] ?? 'info'),
            trim((string) ($data['file'] ?? '')),
            is_numeric($line) ? (int) $line : null,
            $title,
            trim((string) ($data['explanation'] ?? '')),
            trim((string) ($data['fix'] ?? '')),
            trim((string) ($data['category'] ?? 'general')) ?: 'general',
            trim((string) ($data['diff'] ?? '')),
        );
    }

    public static function rank(string $severity): int
    {
        return self::SEVERITIES[strtolower($severity)] ?? -1;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function getRank(): int
    {
        return self::SEVERITIES[$this->severity];
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function getLine(): ?int
    {
        return $this->line;
    }

    public function getLocation(): string
    {
        return $this->file . ($this->line !== null ? ':' . $this->line : '');
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getExplanation(): string
    {
        return $this->explanation;
    }

    public function getFix(): string
    {
        return $this->fix;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getDiff(): string
    {
        return $this->diff;
    }

    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'category' => $this->category,
            'file' => $this->file,
            'line' => $this->line,
            'title' => $this->title,
            'explanation' => $this->explanation,
            'fix' => $this->fix,
            'diff' => $this->diff,
        ];
    }
}