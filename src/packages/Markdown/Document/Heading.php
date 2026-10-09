<?php

declare(strict_types=1);

namespace NeoPHP\Package\Markdown\Document;

class Heading
{
    public function __construct(protected int $level, protected string $text, protected string $id)
    {
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAnchor(): string
    {
        return '#' . $this->id;
    }

    public function toArray(): array
    {
        return ['level' => $this->level, 'text' => $this->text, 'id' => $this->id];
    }
}