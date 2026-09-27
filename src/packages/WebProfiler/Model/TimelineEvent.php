<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Model;

class TimelineEvent
{
    public function __construct(
        protected string $name,
        protected float $start,
        protected float $duration,
        protected string $category = 'default',
        protected ?int $memory = null,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new static(
            (string) ($data['name'] ?? ''),
            (float) ($data['start'] ?? 0),
            (float) ($data['duration'] ?? 0),
            (string) ($data['category'] ?? 'default'),
            isset($data['memory']) ? (int) $data['memory'] : null,
        );
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStart(): float
    {
        return $this->start;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public function getEnd(): float
    {
        return $this->start + $this->duration;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getMemory(): ?int
    {
        return $this->memory;
    }
}